const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const files = {'/':'index.html','/index.html':'index.html','/styles.css':'styles.css','/app.js':'app.js','/assets/rabbit.svg':'assets/rabbit.svg'};
http.createServer((req,res)=>{const file=files[new URL(req.url,'http://localhost').pathname];if(!file){res.writeHead(404);return res.end('Not found');}res.setHeader('Content-Type',{'html':'text/html','css':'text/css','js':'text/javascript','svg':'image/svg+xml'}[file.split('.').pop()]);fs.createReadStream(path.join(__dirname,file)).pipe(res);}).listen(process.env.PORT || 3000,'0.0.0.0',()=>console.log('Petish running on port '+(process.env.PORT || 3000)));
