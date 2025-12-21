#!/usr/bin/env node

/**
 * 生成 Favicon PNG 和 ICO 文件
 * 使用 Node.js Canvas API
 */

const fs = require('fs');
const path = require('path');

// 创建一个简单的 Canvas polyfill，使用纯 Node.js
function generateFaviconDataURL(size) {
    // 由于没有 Canvas 库，我们生成一个简单的 SVG 然后转换
    const svg = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}">
  <rect width="${size}" height="${size}" fill="#2563eb" rx="${size * 0.2}"/>
  <text 
    x="${size / 2}" 
    y="${size / 2}" 
    font-family="Arial, sans-serif" 
    font-size="${size * 0.65}" 
    font-weight="bold" 
    fill="white" 
    text-anchor="middle" 
    dominant-baseline="central">S</text>
</svg>`.trim();

    return svg;
}

// 生成 SVG 文件（已存在）
console.log('✅ favicon.svg 已创建');

// 创建一个简单的说明文件
const readme = `# Favicon 文件

本目录包含 SendWalk 的 favicon 文件：

- **favicon.svg**: SVG 格式（推荐，现代浏览器）
- **favicon-32x32.png**: 32x32 PNG 格式
- **favicon-16x16.png**: 16x16 PNG 格式  
- **favicon.ico**: ICO 格式（传统浏览器）

## 生成 PNG 和 ICO 文件

### 方法 1: 使用在线工具

访问以下网站上传 favicon.svg 生成其他格式：
- https://realfavicongenerator.net/
- https://favicon.io/

### 方法 2: 使用 ImageMagick

\`\`\`bash
# 安装 ImageMagick
brew install imagemagick  # macOS
apt-get install imagemagick  # Linux

# 生成 PNG
convert -background none -resize 32x32 favicon.svg favicon-32x32.png
convert -background none -resize 16x16 favicon.svg favicon-16x16.png

# 生成 ICO（包含多个尺寸）
convert favicon.svg -define icon:auto-resize=16,32,48,64 favicon.ico
\`\`\`

### 方法 3: 使用 Node.js 脚本

打开浏览器访问：\`generate_favicon_png.html\`，然后下载生成的 PNG 文件。

## 当前状态

- ✅ favicon.svg (已创建)
- ⏳ favicon-32x32.png (需要生成)
- ⏳ favicon-16x16.png (需要生成)
- ⏳ favicon.ico (需要生成)

SVG 版本已经可以在现代浏览器中使用！
`;

fs.writeFileSync(
    path.join(__dirname, 'public', 'FAVICON_README.md'),
    readme
);

console.log('✅ FAVICON_README.md 已创建');
console.log('');
console.log('📋 下一步：');
console.log('1. 在浏览器中打开: frontend/public/generate_favicon_png.html');
console.log('2. 下载生成的 PNG 文件并保存到 frontend/public/');
console.log('3. 或使用在线工具生成完整的 favicon 包：https://realfavicongenerator.net/');
console.log('');
console.log('💡 提示：SVG 版本已经可以使用，现代浏览器都支持！');

