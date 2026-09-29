# سایت چنته (لوله بازکن چنته پارس)

لندینگ‌پیج استاتیک (HTML/CSS/JS خالص، بدون نیاز به build) برای هاست cPanel.

## ساختار
```
site/
├── index.html          صفحه اصلی (SEO + Schema.org + FAQ)
├── .htaccess           فشرده‌سازی، کش، هدرهای امنیتی
├── robots.txt / sitemap.xml
└── assets/
    ├── fonts/          فونت وزیرمتن (لوکال، بدون وابستگی به CDN خارجی)
    └── img/            عکس‌های بهینه‌شده (webp + jpg) و favicon
```

## آپلود روی cPanel
1. محتوای پوشه `site/` را zip کنید (فایل مخفی `.htaccess` هم باید داخلش باشد).
2. در cPanel → File Manager → `public_html` → Upload → سپس Extract.
3. اگر دامنه `chanteh.ir` نیست، آدرس را در `index.html`، `robots.txt` و `sitemap.xml` عوض کنید.
4. بعد از فعال شدن SSL (AutoSSL)، خطوط Force HTTPS در `.htaccess` را از کامنت دربیاورید.
