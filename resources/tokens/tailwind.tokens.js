/**
 * GeckoChat — tema de Tailwind a partir de los mismos tokens de tokens.css.
 * No duplica valores: cada color referencia la variable CSS correspondiente,
 * así que una empresa que cambia su branding no necesita una build distinta
 * de Tailwind — solo cambian las variables en :root (o en el iframe del widget).
 *
 * Uso: module.exports = { theme: { extend: require('./tokens/tailwind.tokens.js') } }
 */

function v(name) {
  return `var(--${name})`;
}

module.exports = {
  colors: {
    primary: {
      DEFAULT: v("color-primary"),
      hover: v("color-primary-hover"),
      active: v("color-primary-active"),
      foreground: v("color-primary-foreground"),
      surface: v("color-primary-surface"),
      border: v("color-primary-border"),
    },
    secondary: {
      DEFAULT: v("color-secondary"),
      hover: v("color-secondary-hover"),
      active: v("color-secondary-active"),
      foreground: v("color-secondary-foreground"),
      surface: v("color-secondary-surface"),
    },
    accent: {
      DEFAULT: v("color-accent"),
      hover: v("color-accent-hover"),
      foreground: v("color-accent-foreground"),
      surface: v("color-accent-surface"),
    },
    background: v("color-background"),
    surface: {
      DEFAULT: v("color-surface"),
      raised: v("color-surface-raised"),
      sunken: v("color-surface-sunken"),
      hover: v("color-surface-hover"),
    },
    text: {
      DEFAULT: v("color-text"),
      secondary: v("color-text-secondary"),
      tertiary: v("color-text-tertiary"),
      disabled: v("color-text-disabled"),
    },
    border: {
      DEFAULT: v("color-border"),
      strong: v("color-border-strong"),
    },
    success: {
      DEFAULT: v("color-success"),
      foreground: v("color-success-foreground"),
      surface: v("color-success-surface"),
      border: v("color-success-border"),
    },
    warning: {
      DEFAULT: v("color-warning"),
      foreground: v("color-warning-foreground"),
      surface: v("color-warning-surface"),
      border: v("color-warning-border"),
    },
    danger: {
      DEFAULT: v("color-danger"),
      foreground: v("color-danger-foreground"),
      surface: v("color-danger-surface"),
      border: v("color-danger-border"),
    },
    info: {
      DEFAULT: v("color-info"),
      foreground: v("color-info-foreground"),
      surface: v("color-info-surface"),
      border: v("color-info-border"),
    },
    bubble: {
      agente: v("color-bubble-agente"),
      "agente-texto": v("color-bubble-agente-texto"),
      cliente: v("color-bubble-cliente"),
      "cliente-texto": v("color-bubble-cliente-texto"),
      ia: v("color-bubble-ia"),
      "ia-borde": v("color-bubble-ia-borde"),
      "nota-interna": v("color-nota-interna"),
      "nota-interna-borde": v("color-nota-interna-borde"),
    },
  },

  fontFamily: {
    display: ["Space Grotesk", "ui-sans-serif", "system-ui", "sans-serif"],
    body: ["Work Sans", "ui-sans-serif", "system-ui", "sans-serif"],
    mono: ["IBM Plex Mono", "ui-monospace", "SFMono-Regular", "monospace"],
  },

  fontSize: {
    xs: [v("text-xs-size"), { lineHeight: v("text-xs-lh") }],
    sm: [v("text-sm-size"), { lineHeight: v("text-sm-lh") }],
    base: [v("text-base-size"), { lineHeight: v("text-base-lh") }],
    md: [v("text-md-size"), { lineHeight: v("text-md-lh") }],
    lg: [v("text-lg-size"), { lineHeight: v("text-lg-lh") }],
    xl: [v("text-xl-size"), { lineHeight: v("text-xl-lh") }],
    "2xl": [v("text-2xl-size"), { lineHeight: v("text-2xl-lh") }],
    "3xl": [v("text-3xl-size"), { lineHeight: v("text-3xl-lh") }],
  },

  fontWeight: {
    regular: v("font-weight-regular"),
    medium: v("font-weight-medium"),
    semibold: v("font-weight-semibold"),
    bold: v("font-weight-bold"),
  },

  spacing: {
    1: v("space-1"), 2: v("space-2"), 3: v("space-3"), 4: v("space-4"),
    5: v("space-5"), 6: v("space-6"), 8: v("space-8"), 10: v("space-10"),
    12: v("space-12"), 16: v("space-16"),
  },

  borderRadius: {
    none: v("radius-none"),
    sm: v("radius-sm"),
    md: v("radius-md"),
    lg: v("radius-lg"),
    full: v("radius-full"),
  },

  boxShadow: {
    sm: v("shadow-sm"),
    md: v("shadow-md"),
    lg: v("shadow-lg"),
  },

  transitionDuration: {
    fast: v("duration-fast"),
    base: v("duration-base"),
    slow: v("duration-slow"),
  },

  transitionTimingFunction: {
    standard: v("ease-standard"),
    salida: v("ease-salida"),
  },

  zIndex: {
    dropdown: v("z-dropdown"),
    sticky: v("z-sticky"),
    overlay: v("z-overlay"),
    modal: v("z-modal"),
    toast: v("z-toast"),
    tooltip: v("z-tooltip"),
  },
};
