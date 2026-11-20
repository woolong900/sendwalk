/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string
  readonly VITE_APP_NAME: string
  /** 退订专用域名（逗号分隔），在这些域名上退订页走同源 /api */
  readonly VITE_UNSUBSCRIBE_HOSTS?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

