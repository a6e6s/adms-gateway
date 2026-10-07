import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

const landingPage = document.querySelector('[data-landing-page]');

if (landingPage) {
    gsap.registerPlugin(ScrollTrigger);
    const media = gsap.matchMedia();

    media.add('(prefers-reduced-motion: no-preference)', () => {
        const intro = gsap.timeline({ defaults: { ease: 'power3.out', duration: 0.85 } });
        intro.from('[data-hero-copy] > *', { y: 24, opacity: 0, stagger: 0.1 })
            .from('[data-hero-panel]', { y: 32, opacity: 0, scale: 0.97 }, 0.2)
            .from('[data-flow-step]', { x: -16, opacity: 0, stagger: 0.12, duration: 0.5 }, 0.65);

        gsap.fromTo('[data-scroll-progress]', { scaleX: 0 }, {
            scaleX: 1,
            ease: 'none',
            scrollTrigger: { trigger: landingPage, start: 'top top', end: 'bottom bottom', scrub: 0.2 },
        });

        gsap.utils.toArray('[data-reveal]').forEach((element) => {
            // Start only on entry so content remains visible if JavaScript is unavailable.
            ScrollTrigger.create({
                trigger: element,
                start: 'top 92%',
                once: true,
                onEnter: () => gsap.from(element, { y: 24, opacity: 0, duration: 0.7, ease: 'power2.out' }),
            });
        });
    }, landingPage);

    if (import.meta.hot) {
        import.meta.hot.dispose(() => media.revert());
    }
}
