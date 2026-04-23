import type { AppRuntimeConfig, OrgChartBootstrap } from './org'

declare global {
  interface Window {
    __APP__?: AppRuntimeConfig
    __ORG_CHART_BOOTSTRAP__?: OrgChartBootstrap
  }
}

export {}
