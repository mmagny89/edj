import './bootstrap.js';
// Polices et icones servies par le site : plus de Google Fonts ni de CDN. Un
// visiteur ne transmet donc plus son adresse IP a un tiers pour lire cette
// page (RGPD), le site s'affiche sans acces externe, et la page « Donnees
// personnelles » dit vrai en annoncant aucun service tiers.
import '@fontsource/akaya-kanadaka/400.css';
import '@fontsource/atkinson-hyperlegible/400.css';
import '@fontsource/atkinson-hyperlegible/700.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/regular.min.css';
import '@fortawesome/fontawesome-free/css/brands.min.css';
import './styles/site.css';

// Evenements saisis dans le back-office, deposes en JSON sur le conteneur du
// carrousel par le gabarit. Les mardis et les 1ers samedis restent calcules
// plus bas : ce sont des regles de recurrence, pas des donnees a ressaisir
// cinquante fois par saison.
const readManagedEvents = () => {
  const carousel = document.querySelector('[data-event-carousel]');

  if (!carousel || !carousel.dataset.events) {
    return [];
  }

  try {
    const parsed = JSON.parse(carousel.dataset.events);

    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    // Un attribut illisible ne doit pas emporter tout le calendrier : les
    // dates recurrentes restent affichees.
    console.error('Évènements illisibles :', error);

    return [];
  }
};


const eventTypeLabels = {
  weekly: 'Mardi soir',
  monthly: '1er samedi',
  special: 'Manifestation',
};

const eventTypeIcons = {
  weekly: 'fa-dice',
  monthly: 'fa-calendar-day',
  special: 'fa-star',
};

// Les titres, descriptions et lieux viennent du back-office : ils s'inserent
// dans du HTML, donc ils s'echappent. Sans cela, un titre contenant « <img
// onerror=... > » s'executerait chez chaque visiteur du site public.
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
}[char]));

const formatDate = (date, options = {}) => new Intl.DateTimeFormat('fr-FR', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  ...options,
}).format(date);

const toDate = (value) => {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
};

const buildSeasonEvents = () => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  // Le calendrier vient entierement du back-office : les mardis et les 3e
  // samedis y sont saisis comme les manifestations. Ils etaient auparavant
  // calcules ici, avec leur titre et leur horaire ecrits dans ce fichier —
  // une date exceptionnelle, une soiree annulee ou un horaire modifie
  // demandaient alors une modification du code et un deploiement.
  const events = readManagedEvents().map((event) => ({
    ...event,
    date: toDate(event.date),
  }));

  return {
    today,
    events: events.sort((a, b) => a.date - b.date),
  };
};

// Toutes les dates a venir, sans plafond : le calendrier est desormais
// saisi dans le back-office, ce qui y figure est ce qu'on veut montrer. Le
// carrousel defile horizontalement et les filtres permettent de se
// restreindre a un type de rendez-vous.
const getUpcomingEvents = (events, filter, today) => events
  .filter((event) => event.date >= today)
  .filter((event) => filter === 'all' || event.type === filter);

const renderCarousel = (events, filter, today) => {
  const eventTrack = document.querySelector('[data-event-track]');
  if (!eventTrack) {
    return;
  }

  const upcomingEvents = getUpcomingEvents(events, filter, today);

  // Le nombre d'evenements affiches s'annonce dans une zone de statut : la
  // piste elle-meme n'est pas « live », qui relirait des dizaines de cartes a
  // chaque changement de filtre.
  const status = document.querySelector('[data-event-status]');
  if (status) {
    status.textContent = upcomingEvents.length
      ? `${upcomingEvents.length} évènement${upcomingEvents.length > 1 ? 's' : ''} à venir.`
      : 'Aucun évènement à venir pour cette sélection.';
  }

  if (!upcomingEvents.length) {
    eventTrack.innerHTML = '<p class="agenda-empty">Aucun événement à venir pour cette sélection.</p>';
    return;
  }

  eventTrack.innerHTML = upcomingEvents.map((event, index) => `
    <article class="event-slide event-slide--${event.type}" ${index === 0 ? 'data-next-slide' : ''}>
      <div class="event-slide__date">
        <time datetime="${event.date.toISOString().slice(0, 10)}">
          <span>${event.date.getDate()}</span>
          <small>${new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(event.date)}</small>
        </time>
      </div>
      <div class="event-slide__content">
        <p><i class="fa-solid ${eventTypeIcons[event.type]}" aria-hidden="true"></i> ${escapeHtml(eventTypeLabels[event.type])}</p>
        <div class="event-slide__title">${escapeHtml(event.title)}</div>
        <strong>${escapeHtml(formatDate(event.date))} · ${escapeHtml(event.time)}</strong>
        ${event.description ? `<small>${escapeHtml(event.description)}</small>` : ''}
        ${event.location ? `<small class="event-slide__place"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> ${escapeHtml(event.location)}</small>` : ''}
      </div>
    </article>
  `).join('');
};

const updateNextEvent = (events, today) => {
  const nextEvent = events.find((event) => event.date >= today);
  if (!nextEvent) {
    return;
  }

  document.querySelectorAll('[data-next-event-title]').forEach((element) => {
    element.textContent = nextEvent.title;
  });

  document.querySelectorAll('[data-next-event-date]').forEach((element) => {
    element.textContent = formatDate(nextEvent.date);
  });

  document.querySelectorAll('[data-next-event-meta]').forEach((element) => {
    element.textContent = `${nextEvent.time} · ${nextEvent.description}`;
  });
};

const animateCounters = () => {
  const counters = document.querySelectorAll('.counter');

  // Le HTML porte deja la valeur finale (visible sans JavaScript, et lue par
  // les moteurs de recherche) : l'animation n'est qu'un plus, et seulement
  // pour qui ne demande pas moins de mouvement.
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches
    || document.documentElement.dataset.motion === 'reduce') {
    return;
  }

  counters.forEach((counter) => {
    counter.innerText = '0';
    const updateCount = () => {
      const target = Number(counter.getAttribute('data-count'));
      const current = Number(counter.innerText.replace(/[^0-9]/g, '')) || 0;
      const increment = Math.max(1, Math.ceil(target / 90));

      if (current < target) {
        counter.innerText = Math.min(current + increment, target);
        window.setTimeout(updateCount, 18);
      } else {
        // Une annee ne prend pas de separateur de milliers : « 2 023 » est faux.
        counter.innerText = counter.hasAttribute('data-plain') ? String(target) : target.toLocaleString('fr-FR');
      }
    };

    const observer = new IntersectionObserver((entries, obs) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          counter.classList.add('animate');
          updateCount();
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    observer.observe(counter);
  });
};

// turbo:load et non DOMContentLoaded : Turbo Drive remplace le corps de la
// page a chaque navigation sans recharger le document, et DOMContentLoaded ne
// se declenche donc qu'au tout premier chargement. Le carrousel, le prochain
// rendez-vous et les compteurs n'etaient plus reconstruits ensuite — le
// calendrier apparaissait vide des qu'on revenait sur l'accueil.
//
// turbo:load se declenche aussi au premier chargement : il remplace
// DOMContentLoaded, il ne s'y ajoute pas. Les autres fichiers du projet
// (modal.js, add-game.js, add-slate.js) ecoutent les deux, ce qui les fait
// tourner deux fois au premier chargement — sans consequence chez eux, mais
// ici les ecouteurs des filtres et des fleches seraient poses en double : un
// clic sur "suivant" ferait defiler de deux cartes.
document.addEventListener('turbo:load', () => {
  const { today, events } = buildSeasonEvents();
  const filters = document.querySelectorAll('.calendar-filter');
  const eventTrack = document.querySelector('[data-event-track]');
  const previousButton = document.querySelector('[data-carousel-prev]');
  const nextButton = document.querySelector('[data-carousel-next]');
  const menuToggle = document.querySelector('[data-menu-toggle]');
  const mainNav = document.querySelector('[data-main-nav]');
  let activeFilter = 'all';

  const refreshCarousel = () => renderCarousel(events, activeFilter, today);

  filters.forEach((filter) => {
    filter.addEventListener('click', () => {
      activeFilter = filter.dataset.filter;
      filters.forEach((item) => {
        item.classList.toggle('is-active', item === filter);
        item.setAttribute('aria-pressed', String(item === filter));
      });
      refreshCarousel();
    });
  });

  previousButton?.addEventListener('click', () => {
    eventTrack?.scrollBy({ left: -360, behavior: 'smooth' });
  });

  nextButton?.addEventListener('click', () => {
    eventTrack?.scrollBy({ left: 360, behavior: 'smooth' });
  });

  menuToggle?.addEventListener('click', () => {
    const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
    menuToggle.setAttribute('aria-expanded', String(!isOpen));
    mainNav?.classList.toggle('is-open', !isOpen);
  });

  mainNav?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      menuToggle?.setAttribute('aria-expanded', 'false');
      mainNav.classList.remove('is-open');
    });
  });

  updateNextEvent(events, today);
  refreshCarousel();
  animateCounters();
});


// Reglages d'accessibilite : quatre options, memorisees ensemble dans le
// navigateur et appliquees sur <html> (data-contrast, data-text, data-links,
// data-motion), que le CSS lit. L'etat est relu a chaque navigation Turbo, qui
// remplace le corps de la page.
const A11Y_KEY = 'edj-a11y';
const A11Y_ATTRIBUTES = {
  contrast: ['data-contrast', 'high'],
  text: ['data-text', 'large'],
  links: ['data-links', 'underline'],
  motion: ['data-motion', 'reduce'],
};

const readA11y = () => {
  try {
    return JSON.parse(window.localStorage.getItem(A11Y_KEY) || '{}');
  } catch (error) {
    return {};
  }
};

const writeA11y = (state) => {
  try {
    window.localStorage.setItem(A11Y_KEY, JSON.stringify(state));
  } catch (error) {
    // Navigation privee : le choix vaut pour la page, sans etre memorise.
  }
};

// « Contraste eleve » demande au systeme : actif tant que le visiteur n'a pas
// choisi lui-meme.
const systemWantsContrast = () => window.matchMedia('(prefers-contrast: more)').matches;

const applyA11y = (state) => {
  Object.entries(A11Y_ATTRIBUTES).forEach(([option, [attribute, value]]) => {
    const active = option === 'contrast' && state.contrast === undefined
      ? systemWantsContrast()
      : Boolean(state[option]);
    if (active) {
      document.documentElement.setAttribute(attribute, value);
    } else if (option === 'contrast' && state.contrast === false) {
      // Refus explicite : « normal » l'emporte sur la preference systeme que le
      // CSS lirait sinon, l'attribut absent la laissant s'appliquer.
      document.documentElement.setAttribute(attribute, 'normal');
    } else {
      document.documentElement.removeAttribute(attribute);
    }
  });
};

applyA11y(readA11y());

document.addEventListener('turbo:load', () => {
  const state = readA11y();
  applyA11y(state);

  const menu = document.querySelector('[data-a11y-menu]');
  if (!menu) {
    return;
  }

  menu.hidden = false;
  const button = menu.querySelector('[data-a11y-button]');
  const panel = menu.querySelector('.a11y-panel');
  const boxes = [...menu.querySelectorAll('[data-a11y-option]')];

  const sync = () => {
    const current = readA11y();
    boxes.forEach((box) => {
      const option = box.dataset.a11yOption;
      box.checked = option === 'contrast' && current.contrast === undefined
        ? systemWantsContrast()
        : Boolean(current[option]);
    });
  };
  sync();

  const setOpen = (open) => {
    panel.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
  };

  button.addEventListener('click', () => setOpen(panel.hidden));

  boxes.forEach((box) => {
    box.addEventListener('change', () => {
      const next = readA11y();
      next[box.dataset.a11yOption] = box.checked;
      writeA11y(next);
      applyA11y(next);
    });
  });

  menu.querySelector('[data-a11y-reset]').addEventListener('click', () => {
    writeA11y({});
    applyA11y({});
    sync();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) {
      setOpen(false);
      button.focus();
    }
  });

  document.addEventListener('click', (event) => {
    if (!panel.hidden && !menu.contains(event.target)) {
      setOpen(false);
    }
  });
});
