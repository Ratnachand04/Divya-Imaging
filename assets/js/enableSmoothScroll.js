/*
 * Lightweight inertial scrolling helper with optional progress indicator.
 * Usage: enableSmoothScroll({ speed: 0.9, ease: 0.12, progressIndicator: true });
 */
(function () {
    const prefersReducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

    const defaultOptions = {
        speed: 0.92, // Higher number = longer travel per wheel tick
        ease: 0.12,  // Lower number = softer easing toward the target
        enableOnTouch: false,
        progressIndicator: false,
        progressColor: '#8a2be2'
    };

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

    function normalizeOptions(options) {
        if (!options) {
            return { ...defaultOptions };
        }
        return {
            speed: typeof options.speed === 'number' ? clamp(options.speed, 0.1, 2) : defaultOptions.speed,
            ease: typeof options.ease === 'number' ? clamp(options.ease, 0.05, 0.4) : defaultOptions.ease,
            enableOnTouch: typeof options.enableOnTouch === 'boolean' ? options.enableOnTouch : defaultOptions.enableOnTouch,
            progressIndicator: typeof options.progressIndicator === 'boolean' ? options.progressIndicator : defaultOptions.progressIndicator,
            progressColor: typeof options.progressColor === 'string' ? options.progressColor : defaultOptions.progressColor
        };
    }

    function buildProgressBar(color) {
        const track = document.createElement('div');
        const bar = document.createElement('div');

        track.className = 'smooth-scroll-progress-track';
        bar.className = 'smooth-scroll-progress-bar';
        bar.style.background = color;

        track.appendChild(bar);
        document.body.appendChild(track);

        return { track, bar };
    }

    function mountProgressStyles() {
        if (document.getElementById('smooth-scroll-progress-style')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'smooth-scroll-progress-style';
        style.textContent = `
            .smooth-scroll-progress-track {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 3px;
                background: rgba(255, 255, 255, 0.1);
                z-index: 9999;
                backdrop-filter: blur(4px);
            }
            .smooth-scroll-progress-bar {
                width: 0;
                height: 100%;
                box-shadow: 0 0 12px rgba(138, 43, 226, 0.7);
                transition: width 0.1s ease-out;
            }
        `;
        document.head.appendChild(style);
    }

    function enableSmoothScroll(options) {
        if (prefersReducedMotion.matches) {
            return null;
        }

        const settings = normalizeOptions(options);
        const touchCapable = 'ontouchstart' in window || (typeof navigator !== 'undefined' && navigator.maxTouchPoints > 0);
        if (touchCapable && !settings.enableOnTouch) {
            return null; // Avoid hijacking scroll on touch devices unless explicitly allowed
        }

        const { progressIndicator, progressColor } = settings;

        let target = window.scrollY;
        let current = target;
        let rafId = null;
        let destroyed = false;

        let progressBar = null;
        if (progressIndicator) {
            mountProgressStyles();
            progressBar = buildProgressBar(progressColor).bar;
        }

        const maxScroll = () => document.documentElement.scrollHeight - window.innerHeight;

        const emitTick = () => {
            document.dispatchEvent(new CustomEvent('smoothScrollTick', {
                detail: {
                    scrollY: current,
                    target,
                    maxScroll: maxScroll()
                }
            }));
        };

        const updateProgress = () => {
            if (!progressBar) {
                return;
            }
            const pct = maxScroll() <= 0 ? 0 : (current / maxScroll()) * 100;
            progressBar.style.width = pct + '%';
        };

        const step = () => {
            current += (target - current) * settings.ease;
            if (Math.abs(target - current) <= 0.35) {
                current = target;
            }
            window.scrollTo(0, current);
            updateProgress();
            emitTick();

            if (current !== target && !destroyed) {
                rafId = requestAnimationFrame(step);
            } else {
                rafId = null;
            }
        };

        const wheelHandler = (event) => {
            if (event.ctrlKey || event.metaKey) {
                return;
            }

            event.preventDefault();
            target += event.deltaY * settings.speed;
            target = clamp(target, 0, maxScroll());

            if (rafId === null) {
                rafId = requestAnimationFrame(step);
            }
        };

        const scrollSyncHandler = () => {
            if (rafId !== null) {
                return;
            }
            target = window.scrollY;
            current = target;
            updateProgress();
            emitTick();
        };

        const resizeHandler = () => {
            target = clamp(target, 0, maxScroll());
            current = clamp(current, 0, maxScroll());
            updateProgress();
            emitTick();
        };

        window.addEventListener('wheel', wheelHandler, { passive: false });
        window.addEventListener('scroll', scrollSyncHandler, { passive: true });
        window.addEventListener('resize', resizeHandler);

        updateProgress();
        emitTick();

        return function destroySmoothScroll() {
            destroyed = true;
            window.removeEventListener('wheel', wheelHandler);
            window.removeEventListener('scroll', scrollSyncHandler);
            window.removeEventListener('resize', resizeHandler);
            if (rafId) {
                cancelAnimationFrame(rafId);
            }
            if (progressBar && progressBar.parentNode) {
                const trackEl = progressBar.parentNode;
                if (trackEl.parentNode) {
                    trackEl.parentNode.removeChild(trackEl);
                }
            }
        };
    }

    window.enableSmoothScroll = enableSmoothScroll;
})();
