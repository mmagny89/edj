// Installation du site comme application (ecran d'accueil).
//
// Chrome et Edge sur Android et sur ordinateur proposent l'installation par
// l'evenement « beforeinstallprompt », emis une seule fois et tot dans le
// chargement : il est capte des l'import de ce module, avant que le pied de
// page n'existe. Safari sur iPhone, Samsung Internet et Firefox n'emettent pas
// cet evenement : le gabarit porte alors le geste a faire, un paragraphe par
// navigateur (data-install-mode), et ce script ne fait que montrer le bon.
//
// Ce fichier est charge par le site public et par l'administration : le service
// worker s'enregistre partout ; l'encart d'installation n'existe que sur le site.

let deferredPrompt = null;

/** Le site tourne-t-il deja comme application installee ? */
const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches === true
  || window.navigator.standalone === true;

/** iPhone ou iPad (l'iPad recent se declare « Macintosh » avec un ecran tactile). */
const isIos = () => /iPhone|iPad|iPod/.test(window.navigator.userAgent)
  || (/Macintosh/.test(window.navigator.userAgent) && window.navigator.maxTouchPoints > 1);

const isSamsungInternet = () => /SamsungBrowser/.test(window.navigator.userAgent);
const isAndroid = () => /Android/.test(window.navigator.userAgent);

/**
 * Comment installer ici : « prompt » (bouton du navigateur disponible), un geste
 * a expliquer (« ios », « samsung », « android » pour les autres navigateurs
 * Android, dont Chrome tant qu'il n'a pas emis sa proposition), ou « none »
 * (deja installe, ou ordinateur sans proposition).
 */
const installMode = () => {
  if (isStandalone()) {
    return 'none';
  }
  if (deferredPrompt) {
    return 'prompt';
  }
  if (isIos()) {
    return 'ios';
  }
  if (isSamsungInternet()) {
    return 'samsung';
  }

  return isAndroid() ? 'android' : 'none';
};

const render = () => {
  const block = document.querySelector('[data-install-app]');
  if (!block) {
    return;
  }

  const mode = installMode();
  block.hidden = 'none' === mode;
  block.querySelectorAll('[data-install-mode]').forEach((element) => {
    element.hidden = element.dataset.installMode !== mode;
  });
};

window.addEventListener('beforeinstallprompt', (event) => {
  // Empeche la mini-barre du navigateur : l'installation se propose dans la page, au clic.
  event.preventDefault();
  deferredPrompt = event;
  render();
});

window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  render();
});

if ('serviceWorker' in navigator) {
  // Un echec (certificat de developpement non approuve, navigation privee) ne
  // bloque rien : le site fonctionne sans.
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}

// turbo:load et non DOMContentLoaded : Turbo remplace le corps de la page a
// chaque navigation, et le pied de page avec lui. Le clic est delegue pour la
// meme raison : un ecouteur pose sur le bouton ne survivrait pas a la visite.
document.addEventListener('turbo:load', render);

document.addEventListener('click', async (event) => {
  if (!(event.target instanceof Element) || !event.target.closest('[data-install-button]') || !deferredPrompt) {
    return;
  }

  const prompt = deferredPrompt;
  // Un evenement ne sert qu'une fois : le navigateur en emettra un autre s'il le peut.
  deferredPrompt = null;
  render();
  await prompt.prompt();
  await prompt.userChoice;
});
