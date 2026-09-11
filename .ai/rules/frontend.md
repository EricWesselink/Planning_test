---
paths:
  - resources/js/**
  - resources/views/**
  - vite.config.js
  - package.json
---

# Frontend

## Load feature libraries after login and only on pages that need them

Do not import PDF.js, Tesseract, or other feature libraries from `resources/js/app.js` or from guest/auth views. Register a dedicated Vite entry in `vite.config.js` and `@push('scripts')` it from the Blade view that needs it. Guest pages (`auth/login`, `auth/setup`) load CSS only.
