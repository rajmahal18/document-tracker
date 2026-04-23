/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        brand: {
          50: '#edf5ff',
          100: '#d9eaff',
          200: '#bddbff',
          300: '#8ec3ff',
          400: '#5aa2ff',
          500: '#2f7af8',
          600: '#1b63df',
          700: '#164db0',
          800: '#163f8b',
          900: '#17356f',
        },
        ink: {
          50: '#f7f9fc',
          100: '#eef2f7',
          200: '#dce4ef',
          300: '#c6d2e1',
          400: '#8fa1b7',
          500: '#64748b',
          600: '#475569',
          700: '#334155',
          800: '#1e293b',
          900: '#0f172a',
        },
      },
      boxShadow: {
        glow: '0 24px 48px rgba(15, 23, 42, 0.08)',
      },
      borderRadius: {
        panel: '1.25rem',
      },
    },
  },
  plugins: [],
}
