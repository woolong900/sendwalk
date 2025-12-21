# Favicon 文件说明

## ⚠️ 重要提示

**这些 favicon 文件是手动制作的，请勿自动生成或覆盖！**

## 📁 文件列表

- `favicon.ico` - 主图标文件（手动制作，完美居中）
- `favicon.svg` - SVG 矢量图标
- `favicon-16x16.png` - 16×16 PNG（浏览器标签页）
- `favicon-32x32.ico` - 32×32 ICO（任务栏、书签）

## 🚫 不要执行的操作

❌ 不要运行自动生成 favicon 的脚本
❌ 不要用其他工具覆盖这些文件
❌ 不要从 SVG 重新生成 ICO

## ✅ 如何更新 Favicon

如果需要更新 favicon：

1. 使用专业工具手动制作新的 `favicon.ico`
2. 确保字母 S 完美居中
3. 测试不同尺寸的显示效果
4. 将新文件放在此目录
5. 运行构建：`npm run build`

## 📋 构建流程

Vite 构建时会自动将 `public` 目录中的文件复制到 `dist` 目录，
所以只需要保持 `public` 目录中的文件是正确的即可。

## 🔄 同步自定义文件

如果你在 `dist` 目录中手动调整了 favicon，
运行以下命令同步到 `public` 目录：

```bash
./sync-icons-to-public.sh
```

这样下次构建时就会使用你的自定义文件。

## 📏 各文件用途

| 文件 | 尺寸 | 用途 |
|------|------|------|
| favicon.ico | 多尺寸 | 浏览器默认图标（IE 兼容）|
| favicon-16x16.png | 16×16 | 浏览器标签页（高清显示）|
| favicon-32x32.ico | 32×32 | 任务栏、书签栏 |
| favicon.svg | 矢量 | 现代浏览器（自动缩放）|

## 🎨 制作工具

推荐的 favicon 制作工具：

- **在线工具**: https://realfavicongenerator.net/
- **Photoshop / Figma**: 导出多尺寸 PNG 后转换
- **ImageMagick**: 命令行批量处理

## 📝 历史记录

- 2024-12-21: 手动制作，解决字母 S 垂直居中问题
- 原因: 自动生成的 icon 字母偏上，手动调整后完美居中

---

**记住：这些文件是精心制作的，不要轻易替换！** ✨

