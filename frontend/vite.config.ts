import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    host: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          // React 核心库
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
          
          // UI 组件库（Radix UI）
          'ui-vendor': [
            '@radix-ui/react-dialog',
            '@radix-ui/react-dropdown-menu',
            '@radix-ui/react-popover',
            '@radix-ui/react-select',
            '@radix-ui/react-tabs',
            '@radix-ui/react-toast',
            '@radix-ui/react-alert-dialog',
            '@radix-ui/react-label',
            '@radix-ui/react-slot',
            '@radix-ui/react-checkbox',
            '@radix-ui/react-progress',
            '@radix-ui/react-switch',
          ],
          
          // 数据查询和表格
          'data-vendor': [
            '@tanstack/react-query',
            '@tanstack/react-table',
          ],
          
          // 图表和可视化
          'chart-vendor': [
            'recharts',
            'reactflow',
          ],
          
          // 表单和验证
          'form-vendor': [
            'react-hook-form',
            '@hookform/resolvers',
            'zod',
          ],
          
          // 工具库
          'utils-vendor': [
            'axios',
            'date-fns',
            'lodash-es',
            'clsx',
            'tailwind-merge',
            'class-variance-authority',
          ],
          
          // 其他库
          'misc-vendor': [
            'lucide-react',
            'sonner',
            'zustand',
            'framer-motion',
            '@dnd-kit/core',
            '@dnd-kit/sortable',
            '@dnd-kit/utilities',
          ],
        },
      },
    },
    // 提高 chunk 大小警告限制到 1000kb（从默认的 500kb）
    chunkSizeWarningLimit: 1000,
    // 启用 CSS 代码分割
    cssCodeSplit: true,
  },
})

