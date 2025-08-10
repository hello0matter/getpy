// 引入所需的核心模块
const http = require("http");
const https = require("https");

const port = 10101; // 定义代理服务器的端口

/**
 * 创建并启动 HTTP 代理服务器
 */
const server = http.createServer(onRequest);

server.listen(port, () => {
    console.log(`✅ 代理服务器已启动，正在监听端口: ${port}`);
    console.log(`🚀 使用方法: http://localhost:${port}/?target=目标网站URL`);
    console.log(`   例如: http://localhost:${port}/?target=https://www.google.com`);
});

/**
 * 处理每一个进入代理的请求
 * @param {http.IncomingMessage} clientReq - 来自客户端（你的浏览器）的请求
 * @param {http.ServerResponse} clientRes - 用于响应客户端（你的浏览器）的对象
 */
function onRequest(clientReq, clientRes) {
    let targetUrl;
    try {
        // 使用现代的 URL API 来解析客户端的请求地址
        const requestUrl = new URL(clientReq.url, `http://${clientReq.headers.host}`);
        targetUrl = requestUrl.searchParams.get("target");

        // 1. 健壮性检查：如果缺少 target 参数，则返回错误
        if (!targetUrl) {
            clientRes.writeHead(400, { "Content-Type": "text/plain; charset=utf-8" });
            clientRes.end("错误：请求中缺少 'target' URL 参数。");
            return;
        }

        // 验证 targetUrl 是否是一个合法的 URL
        new URL(targetUrl);

    } catch (error) {
        clientRes.writeHead(400, { "Content-Type": "text/plain; charset=utf-8" });
        clientRes.end(`错误：提供的 'target' URL 无效。${error.message}`);
        return;
    }

    // 解析目标 URL，获取协议、主机名等信息
    const target = new URL(targetUrl);

    // 2. 动态选择 http 或 https 模块
    const agent = target.protocol === "https:" ? https : http;

    // 3. 准备代理请求的配置
    const options = {
        hostname: target.hostname,
        port: target.port || (target.protocol === "https:" ? 443 : 80), // 动态端口
        path: target.pathname + target.search, // 包含路径和查询参数
        method: clientReq.method, // 保持原始请求方法 (GET, POST, etc.)
        headers: {
            ...clientReq.headers,
            host: target.hostname, // **重要**：必须将 host 头修改为目标服务器的 host
        },
    };

    // 4. 发起代理请求
    const proxyReq = agent.request(options, (targetRes) => {
        console.log(`[${new Date().toLocaleTimeString()}] 代理请求: ${targetUrl} [${targetRes.statusCode}]`);

        // 5. 修改从目标服务器返回的响应头
        const headers = { ...targetRes.headers };
        // 移除这两个关键的安全头，以允许 iframe 加载
        delete headers["x-frame-options"];
        delete headers["content-security-policy"];

        // 将状态码和修改后的响应头写回给客户端
        clientRes.writeHead(targetRes.statusCode, headers);

        // 将目标服务器的响应体通过管道流回给客户端
        targetRes.pipe(clientRes, { end: true });
    });

    // 6. 错误处理：如果代理请求失败（如DNS解析失败、服务器拒绝连接）
    proxyReq.on("error", (error) => {
        console.error(`代理请求到 ${targetUrl} 时发生错误: ${error.message}`);
        clientRes.writeHead(502, { "Content-Type": "text/plain; charset=utf-8" });
        clientRes.end(`代理网关错误：无法连接到目标服务器。\n${error.message}`);
    });

    // 将客户端的请求体通过管道流给代理请求（用于处理 POST 等带 body 的请求）
    clientReq.pipe(proxyReq, { end: true });
}
