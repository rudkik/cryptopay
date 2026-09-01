/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        bg: '#0a0613',
        surface: '#150d27',
        'surface-2': '#1e1438',
        border: '#2d2050',
        primary: {
          DEFAULT: '#8b5cf6',
          hover: '#a78bfa',
        },
        accent: '#d946ef',
        text: '#ece8f6',
        muted: '#9d94b8',
        success: '#34d399',
        warning: '#fbbf24',
        danger: '#f87171',
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
        card: '0 1px 2px rgba(0,0,0,.4), 0 8px 24px -12px rgba(0,0,0,.6)',
        glow: '0 0 0 1px rgba(139,92,246,.35), 0 8px 40px -12px rgba(139,92,246,.45)',
        'glow-sm': '0 0 18px -4px rgba(139,92,246,.55)',
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
