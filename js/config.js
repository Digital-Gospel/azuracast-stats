/**
 * Public, non-secret frontend configuration.
 *
 * Do not place API keys or other secrets in this file.
 */
window.APP_CONFIG = Object.freeze({
  APP_VERSION: "2.3.0",

  // Must match AZURACAST_STATION_ID in azuracast-api-proxy.env.
  STATION_ID: 1,

  // Turnstile site keys are public and are safe to expose in client-side code.
  // Replace this placeholder with the site key registered for your dashboard domain.
  TURNSTILE_SITE_KEY: "YOUR_TURNSTILE_SITE_KEY",

  // Keep false in production. Enable only while troubleshooting in a safe environment.
  DEBUG: false,

  // English is used throughout the UI.
  LOCALE: "en-US",
});
