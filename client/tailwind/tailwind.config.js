/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './**/*.html', // Scans all HTML files in the project directory
    './src/**/*.{js,jsx,ts,tsx}', // Scans all JS/TS files in the src directory
    './components/**/*.{js,jsx,ts,tsx}', // Scans all JS/TS files in the components directory
    './pages/**/*.{js,jsx,ts,tsx}', // Scans all JS/TS files in the pages directory
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
