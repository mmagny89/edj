// Mesure d'audience Matomo, hebergee par l'association : aucun tiers.
//
// Configuree pour rester dans l'exemption de consentement de la CNIL :
//   - aucun cookie (disableCookies) : un visiteur n'est pas suivi d'une visite a
//     l'autre ni d'un site a l'autre ;
//   - « Do Not Track » et « Global Privacy Control » respectes : on ne charge rien ;
//   - refus durable par la case de la page « Donnees personnelles », memorise dans
//     le navigateur (localStorage), jamais cote serveur ;
//   - l'URL envoyee n'a ni requete ni fragment : un lien signe de l'espace
//     adherent (?_hash=...) ne doit pas quitter le site.
// L'anonymisation de l'adresse IP et la duree de conservation se reglent dans
// Matomo (Parametres > Confidentialite), pas ici.

const OPT_OUT_KEY = 'edj-audience-refus';

const meta = document.querySelector('meta[name="matomo"]');
const base = meta ? meta.content.replace(/\/?$/, '/') : null;
const siteId = meta ? meta.dataset.siteId : null;

const storage = {
  get() {
    try {
      return window.localStorage.getItem(OPT_OUT_KEY) === '1';
    } catch (error) {
      return false;
    }
  },
  set(refused) {
    try {
      if (refused) {
        window.localStorage.setItem(OPT_OUT_KEY, '1');
      } else {
        window.localStorage.removeItem(OPT_OUT_KEY);
      }
    } catch (error) {
      // Stockage indisponible : le choix vaut pour la page en cours seulement.
    }
  },
};

const browserRefuses = () => navigator.doNotTrack === '1'
  || window.doNotTrack === '1'
  || navigator.globalPrivacyControl === true;

const isTracking = () => Boolean(base && siteId) && !storage.get() && !browserRefuses();

const paq = (window._paq = window._paq || []);
let loaded = false;
let previousUrl = null;

const cleanUrl = () => window.location.origin + window.location.pathname;

const load = () => {
  if (loaded) {
    return;
  }
  loaded = true;

  paq.push(['disableCookies']);
  paq.push(['setDoNotTrack', true]);
  paq.push(['setTrackerUrl', `${base}matomo.php`]);
  paq.push(['setSiteId', siteId]);
  paq.push(['enableLinkTracking']);

  const script = document.createElement('script');
  script.async = true;
  script.src = `${base}matomo.js`;
  document.head.appendChild(script);
};

const trackPage = () => {
  if (!isTracking()) {
    return;
  }
  load();

  if (previousUrl) {
    paq.push(['setReferrerUrl', previousUrl]);
  }
  paq.push(['setCustomUrl', cleanUrl()]);
  paq.push(['setDocumentTitle', document.title]);
  paq.push(['trackPageView']);
  previousUrl = cleanUrl();
};

// Case de refus de la page « Donnees personnelles » : cachee sans JavaScript.
const syncOptOut = () => {
  document.querySelectorAll('[data-audience-optout]').forEach((box) => {
    const input = box.querySelector('input[type="checkbox"]');
    if (!input) {
      return;
    }
    box.hidden = !base;
    // Cochee : la visite est mesuree.
    input.checked = !storage.get();
    const status = box.querySelector('[data-audience-status]');
    if (status) {
      status.textContent = browserRefuses()
        ? 'Votre navigateur demande de ne pas être suivi : aucune visite n\'est mesurée.'
        : '';
    }
  });
};

document.addEventListener('change', (event) => {
  const input = event.target;
  if (input instanceof HTMLInputElement && input.closest('[data-audience-optout]')) {
    storage.set(!input.checked);
  }
});

// Turbo emet turbo:load au premier chargement puis a chaque navigation.
document.addEventListener('turbo:load', () => {
  syncOptOut();
  trackPage();
});
