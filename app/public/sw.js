/*
 * Service worker d'Envie de Jouer : aucun cache, aucune notification.
 *
 * Il n'existe que parce que certains navigateurs, dont Samsung Internet, ne
 * proposent l'installation qu'a un site dont le service worker gere « fetch ».
 * Le gestionnaire ci-dessous ne repond a rien : toute requete part au reseau
 * comme sans service worker, et rien n'est jamais garde. Le site reste donc
 * toujours a jour, et ne s'affiche pas hors connexion.
 */
self.addEventListener('fetch', () => {});
