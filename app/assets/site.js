import './bootstrap.js';
import './styles/site.css';

// Evenements saisis dans le back-office, deposes en JSON sur le conteneur du
// carrousel par le gabarit. Les mardis et les 3e samedis restent calcules
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
  monthly: '3e samedi',
  special: 'Manifestation',
};

const eventTypeIcons = {
  weekly: 'fa-dice',
  monthly: 'fa-calendar-day',
  special: 'fa-star',
};

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

const getUpcomingEvents = (events, filter, today) => events
  .filter((event) => event.date >= today)
  .filter((event) => filter === 'all' || event.type === filter)
  .slice(0, 14);

const renderCarousel = (events, filter, today) => {
  const eventTrack = document.querySelector('[data-event-track]');
  if (!eventTrack) {
    return;
  }

  const upcomingEvents = getUpcomingEvents(events, filter, today);

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
        <p><i class="fa-solid ${eventTypeIcons[event.type]}" aria-hidden="true"></i> ${eventTypeLabels[event.type]}</p>
        <h3>${event.title}</h3>
        <strong>${formatDate(event.date)} · ${event.time}</strong>
        <small>${event.description}</small>
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

  counters.forEach((counter) => {
    const updateCount = () => {
      const target = Number(counter.getAttribute('data-count'));
      const current = Number(counter.innerText.replace(/[^0-9]/g, '')) || 0;
      const increment = Math.max(1, Math.ceil(target / 90));

      if (current < target) {
        counter.innerText = Math.min(current + increment, target);
        window.setTimeout(updateCount, 18);
      } else {
        counter.innerText = target.toLocaleString('fr-FR');
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

document.addEventListener('DOMContentLoaded', () => {
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
      filters.forEach((item) => item.classList.toggle('is-active', item === filter));
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
