"use strict";

let html = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => {
    html += chunk;
});
process.stdin.on("end", () => {
    const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)]
        .map((match) => match[1])
        .join("\n");
    // Compilation only: browser globals are intentionally not executed.
    new Function(scripts);
    process.stdout.write("inline JavaScript syntax: PASS\n");
});
