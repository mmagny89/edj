import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/site.css';

// Animate the main title and subtitle on page load
document.addEventListener('DOMContentLoaded', () => {
  const titrePrincipal = document.getElementById('titre-principal');
  const sousTitre = document.getElementById('sous-titre');

  if (titrePrincipal && sousTitre) {
    titrePrincipal.classList.add('visible');

    const delaiAnimation = 800; // Délai en millisecondes
    setTimeout(() => {
      sousTitre.classList.add('visible');
    }, delaiAnimation);
  }
});

// Counter animation script
document.addEventListener('DOMContentLoaded', () => {
  const counters = document.querySelectorAll('.counter');
  const speed = 200;

  counters.forEach(counter => {
    const updateCount = () => {
      const target = +counter.getAttribute('data-count');
      const count = +counter.innerText.replace(/[^0-9]/g, '');
      const inc = target / speed;

      if (count < target) {
        counter.innerText = Math.ceil(count + inc);
        setTimeout(updateCount, 1);
      } else {
        counter.innerText = target;
      }
    };

    const observer = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          counter.classList.add('animate');
          updateCount();
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 1 });

    observer.observe(counter);
  });
});
