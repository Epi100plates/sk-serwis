/// <reference types="astro/client" />

interface Window {
  dataLayer: unknown[];
  skTrack?: (name: string, params?: Record<string, unknown>) => void;
  __skAnalyticsLoaded?: boolean;
  __skAdsSendTo?: string;
}
