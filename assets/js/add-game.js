document.addEventListener('DOMContentLoaded', () => initializeModalToggles());
document.addEventListener('turbo:load', () => initializeModalToggles());

function initializeModalToggles() {
  // Fonction de debouncing
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Fonction pour initialiser l'autocomplétion dans la modale
  function initializeModal(modal) {
    console.log('Initialisation de la modale'); // Débogage
    const gameInput = document.getElementById('add_game_name');
    if (!gameInput) {
      console.error('Champ gameInput non trouvé dans le DOM');
      return;
    }

    // Réinitialiser le formulaire
    const form = modal.querySelector('#add-game-form');
    if (form) {
      form.reset();
    }

    const suggestionsContainer = document.getElementById('suggestions-container');
    const searchLoader = document.getElementById('search-loader');
    const detailsLoader = document.getElementById('details-loader');

    // Fonction pour effectuer la recherche
    const searchGames = debounce(async (query) => {
      if (query.length < 3) {
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('block', 'hidden');
        }
        return;
      }

      try {
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('hidden', 'block');
        }
        console.log('Envoi de la requête pour la recherche :', query); // Débogage
        const response = await fetch(`/app/games/search?query=${encodeURIComponent(query)}`, {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const games = await response.json();

        suggestionsContainer.innerHTML = '';
        if (games.length > 0) {
          suggestionsContainer.className = suggestionsContainer.className.replace('hidden', 'block');
          games.forEach(game => {
            const div = document.createElement('div');
            div.className = 'px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer';
            div.textContent = game.name;
            div.addEventListener('click', async () => {
              gameInput.value = game.name;
              document.getElementById('add_game_bggId').value = game.id;

              // Récupérer les détails du jeu, y compris l'image
              try {
                if (detailsLoader) {
                  detailsLoader.className = detailsLoader.className.replace('hidden', 'block');
                }
                const detailsResponse = await fetch(`/app/games/${game.id}/details`, {
                  headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                  }
                });
                if (!detailsResponse.ok) {
                  throw new Error(`Erreur HTTP: ${detailsResponse.status}`);
                }
                const details = await detailsResponse.json();
                document.getElementById('add_game_imageUrl').value = details.image || '';
              } catch (error) {
                console.error('Erreur lors de la récupération des détails du jeu :', error);
                document.getElementById('add_game_imageUrl').value = '';
              } finally {
                if (detailsLoader) {
                  detailsLoader.className = detailsLoader.className.replace('block', 'hidden');
                }
              }

              suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
              suggestionsContainer.innerHTML = '';
            });
            suggestionsContainer.appendChild(div);
          });
        } else {
          suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        }
      } catch (error) {
        console.error('Erreur lors de la recherche de jeux :', error);
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
      } finally {
        if (searchLoader) {
          searchLoader.className = searchLoader.className.replace('block', 'hidden');
        }
      }
    }, 300);

    // Ajouter l'écouteur pour l'autocomplétion
    gameInput.addEventListener('input', () => {
      const query = gameInput.value;
      searchGames(query);
    });

    // Cacher les suggestions lors d'un clic à l'extérieur
    document.addEventListener('click', (e) => {
      if (!gameInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
        suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
        suggestionsContainer.innerHTML = '';
      }
    });
  }

  // Supprimer les anciens écouteurs pour éviter les doublons
  const modalToggles = document.querySelectorAll('[data-modal-toggle]');
  modalToggles.forEach(toggle => {
    const newToggle = toggle.cloneNode(true);
    toggle.parentNode.replaceChild(newToggle, toggle);
    newToggle.addEventListener('click', () => {
      const modalId = newToggle.getAttribute('data-modal-toggle');
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.toggle('hidden');
        if (!modal.classList.contains('hidden')) {
          initializeModal(modal);
        }
      } else {
        console.error('Modale non trouvée pour ID :', modalId);
      }
    });
  });

  // Gérer la suppression des jeux via AJAX
  const deleteForms = document.querySelectorAll('form[action*="/app/events/"]');
  deleteForms.forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        if (!response.ok) {
          throw new Error(`Erreur HTTP: ${response.status}`);
        }
        const result = await response.json();
        if (result.success) {
          form.closest('article').remove(); // Supprimer l'élément du DOM
        }
      } catch (error) {
        console.error('Erreur lors de la suppression du jeu :', error);
      }
    });
  });
}
