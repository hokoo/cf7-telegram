// scripts/postbuild-stable.js
const fs = require("fs");
const path = require("path");

const buildDir = path.resolve(__dirname, "..", "build");
const manifestPath = path.join(buildDir, "asset-manifest.json");

function rmIfExists(p) {
    try {
        fs.unlinkSync(p);
    } catch (e) {
        if (e.code !== "ENOENT") throw e;
    }
}

function copyAndRemove(manifest, key, stableRel) {
    const relFromManifest = (manifest.files?.[key] || "").replace(/^\//, "");
    if (!relFromManifest) {
        console.warn(`[postbuild] manifest.files["${key}"] not found, skip`);
        return;
    }

    const src = path.join(buildDir, relFromManifest);
    const dst = path.join(buildDir, stableRel);

    if (!fs.existsSync(src)) {
        console.warn(`[postbuild] Source file does not exist: ${relFromManifest}`);
        return;
    }

    fs.mkdirSync(path.dirname(dst), { recursive: true });
    fs.copyFileSync(src, dst);
    console.log(`[postbuild] Copied ${relFromManifest} -> ${stableRel}`);

    // Remove original hashed file
    rmIfExists(src);
    console.log(`[postbuild] Removed ${relFromManifest}`);

    // Remove sourcemap for original hashed file (if present)
    rmIfExists(src + ".map");
    if (fs.existsSync(src + ".map")) {
        // no-op; rmIfExists already handled ENOENT; kept for clarity
    }

    // Also remove manifest-listed map key if it exists (CRA uses "<file>.map" keys)
    const mapKey = `${path.posix.basename(relFromManifest)}.map`;
    const mapRel = (manifest.files?.[mapKey] || "").replace(/^\//, "");
    if (mapRel) {
        rmIfExists(path.join(buildDir, mapRel));
        delete manifest.files[mapKey];
    }

    // Update manifest to point main.* to stable file
    manifest.files[key] = "/" + stableRel.replace(/\\/g, "/");
}

function run() {
    if (!fs.existsSync(manifestPath)) {
        throw new Error(`asset-manifest.json not found at ${manifestPath}`);
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));

    // Make stable filenames
    copyAndRemove(manifest, "main.js", "static/js/main.js");
    copyAndRemove(manifest, "main.css", "static/css/main.css");

    // Update entrypoints (replace hashed with stable)
    if (Array.isArray(manifest.entrypoints)) {
        manifest.entrypoints = manifest.entrypoints.map((p) => {
            const clean = String(p).replace(/^\//, "");
            if (/^static\/js\/main\.[a-f0-9]+\.js$/i.test(clean)) return "static/js/main.js";
            if (/^static\/css\/main\.[a-f0-9]+\.css$/i.test(clean)) return "static/css/main.css";
            return p;
        });
    }

    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + "\n", "utf8");
    console.log("[postbuild] Updated asset-manifest.json to stable main.js/main.css");
}

run();
