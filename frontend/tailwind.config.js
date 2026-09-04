/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        // Warm light palette — see SPEC §9.
        bg: '#f6f5f0',
        surface: '#ffffff',
        'surface-2': '#f1efe8',
        border: {
          DEFAULT: '#e5e2d9',
          strong: '#d6d2c6',
        },
        primary: {
          DEFAULT: '#6d4df2',
          hover: '#5a3bdc',
          soft: '#efeaff',
          on: '#ffffff',
        },
        accent: {
          DEFAULT: '#0ea5a4',
          // Darkened for text use: #0ea5a4 only clears 3:1 on white.
          ink: '#0f6f6e',
          soft: '#e2f7f6',
        },
        text: '#1c1b1f',
        // Spec called for #6f6d78; that lands at 4.41:1 on surface-2, so it is
        // nudged darker to clear WCAG AA on both white and surface-2.
        muted: '#63616c',
        // Both nudged a step darker than the brief: #15803d / #b45309 land at
        // 4.48 / 4.56 on their own soft tints, which is at or below AA.
        success: { DEFAULT: '#136c33', soft: '#e6f6ec' },
        warning: { DEFAULT: '#a24a08', soft: '#fdf3e1' },
        danger: { DEFAULT: '#b91c1c', soft: '#fdeaea' },
        info: { DEFAULT: '#1d4ed8', soft: '#e8efff' },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      spacing: {
        18: '4.5rem',
        22: '5.5rem',
      },
      borderRadius: {
        '2xl': '1rem',
      },
      boxShadow: {
        card: '0 1px 2px rgba(20,16,40,.06), 0 8px 24px rgba(20,16,40,.06)',
        pop: '0 2px 6px rgba(20,16,40,.08), 0 16px 40px rgba(20,16,40,.12)',
        glow: '0 1px 2px rgba(20,16,40,.06), 0 10px 30px rgba(109,77,242,.12)',
        'glow-sm': '0 1px 2px rgba(20,16,40,.06), 0 4px 12px rgba(109,77,242,.20)',
      },
      keyframes: {
        'fade-in': {
          from: { opacity: '0' },
          to: { opacity: '1' },
        },
        'slide-up': {
          from: { opacity: '0', transform: 'translateY(8px)' },
          to: { opacity: '1', transform: 'translateY(0)' },
        },
        'slide-in-right': {
          from: { opacity: '0', transform: 'translateX(16px)' },
          to: { opacity: '1', transform: 'translateX(0)' },
        },
        shimmer: {
          '100%': { transform: 'translateX(100%)' },
        },
        'draw-check': {
          to: { 'stroke-dashoffset': '0' },
        },
        'pulse-ring': {
          '0%': { transform: 'scale(.85)', opacity: '.7' },
          '70%': { transform: 'scale(1.6)', opacity: '0' },
          '100%': { transform: 'scale(1.6)', opacity: '0' },
        },
      },
      animation: {
        'fade-in': 'fade-in .2s ease-out both',
        'slide-up': 'slide-up .25s cubic-bezier(.16,1,.3,1) both',
        'slide-in-right': 'slide-in-right .25s cubic-bezier(.16,1,.3,1) both',
        shimmer: 'shimmer 1.6s infinite',
        'draw-check': 'draw-check .6s .15s cubic-bezier(.65,0,.45,1) forwards',
        'pulse-ring': 'pulse-ring 2s cubic-bezier(.24,0,.38,1) infinite',
      },
    },
  },
  plugins: [],
}
