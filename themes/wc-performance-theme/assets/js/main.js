/**
 * WC Performance Theme — Main JS
 * Zero jQuery dependency for frontend interactions.
 */

(function () {
    'use strict';

    // --- Lazy Load Images (IntersectionObserver fallback) ---
    if ('IntersectionObserver' in window) {
        const lazyImages = document.querySelectorAll('img[data-src]');
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    if (img.dataset.srcset) img.srcset = img.dataset.srcset;
                    img.removeAttribute('data-src');
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '200px' });

        lazyImages.forEach(function (img) { observer.observe(img); });
    }

    // --- Smooth scroll for anchor links ---
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // --- Mobile navigation toggle ---
    var navToggle = document.querySelector('.nav-toggle');
    if (navToggle) {
        navToggle.addEventListener('click', function () {
            document.querySelector('.site-nav').classList.toggle('open');
        });
    }

    // --- Back to top button ---
    var backToTop = document.createElement('button');
    backToTop.className = 'back-to-top';
    backToTop.setAttribute('aria-label', 'Back to top');
    backToTop.innerHTML = '&uarr;';
    backToTop.style.cssText = 'display:none;position:fixed;bottom:2rem;right:2rem;width:40px;height:40px;border-radius:50%;background:#2563eb;color:#fff;border:none;cursor:pointer;font-size:1.2rem;z-index:99;box-shadow:0 2px 8px rgba(0,0,0,0.2);';
    document.body.appendChild(backToTop);

    window.addEventListener('scroll', function () {
        backToTop.style.display = window.scrollY > 300 ? 'block' : 'none';
    });
    backToTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

})();
