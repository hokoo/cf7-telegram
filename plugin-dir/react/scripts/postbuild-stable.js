const fs = require("fs");
const path = require("path");

const buildDir = path.resolve(__dirname, "..", "build");
const manifest = JSON.parse(fs.readFileSync(path.join(buildDir, "asset-manifest.json"), "utf8"));

function copyFromManifest(key, targetRel) {
    const rel = (manifest.files[key] || "").replace(/^\//, "");
    if (!rel) return;

    const src = path.join(buildDir, rel);
    const dst = path.join(buildDir, targetRel);

    fs.mkdirSync(path.dirname(dst), { recursive: true });
    fs.copyFileSync(src, dst);
    console.log(`Copied ${rel} -> ${targetRel}`);
}

copyFromManifest("main.js",  "static/js/main.js");
copyFromManifest("main.css", "static/css/main.css");
