// Local disposable runner: executes the actual collection requests and assertions.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const {spawn, execFileSync} = require('child_process');
const base = path.resolve(__dirname, '../..');
if (path.basename(base) !== '.staging-newman-disposable' || process.env.APP_ENV !== 'testing' ||
    process.env.DB_CONNECTION !== 'sqlite' || fs.realpathSync(process.env.DB_DATABASE) !== fs.realpathSync(path.join(base,'isolated.sqlite'))) {
  throw new Error('Refusing to run outside disposable SQLite test copy');
}
const newman = require(process.env.NEWMAN_MODULE);
const source = JSON.parse(fs.readFileSync(path.join(base,'docs/rustik-api-v1.postman_collection.json'),'utf8'));
const items = source.item.flatMap(x => x.item || [x]);
const fixtures = JSON.parse(execFileSync(process.env.STAGING_PHP, [path.join(base,'tests/Staging/newman-fixtures.php')], {cwd:base, encoding:'utf8'}));
const admin = fixtures.accounts.administrador, seller = fixtures.accounts.vendedor, kitchen = fixtures.accounts.cocina;
const uuid = () => crypto.randomUUID();
const orderId = uuid(), orderOperation = uuid(), shiftUuid = uuid();
const keys = {base_url:'http://127.0.0.1:18764/api/v1', token:'', email:seller.email, password:fixtures.password,
  new_email:crypto.randomBytes(12).toString('hex')+'@example.invalid', new_password:crypto.randomBytes(20).toString('hex'),
  user_id:String(fixtures.target_id), catalog_type:'categories', catalog_id:String(fixtures.category_id),
  order_uuid:orderId, operation_uuid:orderOperation, shift_uuid:shiftUuid, shift_id:'', kitchen_token:kitchen.token};
const plan = [
  ['Health',null], ['Sin token: 401',null], ['Login',null], ['Me',seller],
  ['Catalog',seller], ['Categories',seller], ['Extras',seller], ['Options',seller],
  ['Order create',seller], ['Reintento idempotente del mismo pedido',seller], ['Conflicto idempotente: 409',seller],
  ['Pedido inválido: 422',seller], ['Orders list',seller], ['Order get',seller], ['Order status',seller], ['Order sync',seller],
  ['Kitchen queue',kitchen], ['Kitchen detail',kitchen], ['Kitchen transition',kitchen],
  ['Report summary',admin], ['Report sales',admin], ['Report products',admin], ['Report payments',admin],
  ['Cash open',seller], ['Cash current',seller], ['Cash history',seller], ['Cash close',seller],
  ['Users list',admin], ['Users get',admin], ['Users create',admin], ['Users update',admin],
  ['Catalog create',admin], ['Catalog update',admin], ['Cocina no accede a reportes: 403',null], ['Logout',seller]
];
if (items.length !== 35 || new Set(plan.map(x => x[0])).size !== 35 || plan.some(([n]) => !items.some(i => i.name === n))) throw new Error('Plan does not cover the whole collection');
async function runItem(name, actor) {
  const variables = {...keys, token:actor ? actor.token : ''};
  if (!['Order create','Reintento idempotente del mismo pedido','Conflicto idempotente: 409'].includes(name)) variables.operation_uuid = uuid();
  const collection = {...source, item:[items.find(i => i.name === name)], variable:Object.entries(variables).map(([key,value]) => ({key,value}))};
  return new Promise((resolve,reject) => {
    let body;
    const runner = newman.run({collection, reporters:[], timeoutRequest:10000}, (err, summary) => {
      if (err) return reject(new Error('Newman execution failed: '+name));
      if (summary.run.failures.length) return reject(new Error(name+': '+summary.run.failures.map(f => f.error.name+': '+f.error.message).join('; ')));
      if (name === 'Cash open') keys.shift_id = String(body.data.id);
      resolve(summary.run.stats.assertions.total);
    });
    runner.on('request', (err,args) => { if (!err && args.response) { try { body = args.response.json(); } catch (_) {} } });
  });
}
(async () => {
  const net = require('net');
  const free = await new Promise(resolve => { const socket = net.connect(18764,'127.0.0.1'); socket.on('connect',()=>{socket.destroy();resolve(false)}); socket.on('error',()=>resolve(true)); });
  if (!free) throw new Error('Loopback test port is occupied');
  const server = spawn(process.env.STAGING_PHP,['-S','127.0.0.1:18764','-t','public','public/index.php'],{cwd:base,stdio:'ignore',windowsHide:true});
  try {
    let ready = false;
    for(let i=0;i<50;i++){ try { const r=await fetch(keys.base_url+'/health'); if(r.ok){ready=true;break;} } catch (_) {} await new Promise(r=>setTimeout(r,100)); }
    if(!ready) throw new Error('Loopback server did not start');
    let assertions=0;
    for(const [name,actor] of plan) { assertions += await runItem(name,actor); }
    console.log(`Newman OK: ${plan.length} requests, ${assertions} assertions, 0 failures.`);
  } finally { server.kill(); }
})().catch(error => { console.error(error.message); process.exitCode=1; });
