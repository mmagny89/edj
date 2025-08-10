document.addEventListener('DOMContentLoaded', () => initializeAddGameModal());
document.addEventListener('turbo:load', () => initializeAddGameModal());

function initializeAddGameModal() {
  console.log('Initialisation de add-game.js');
  const modal = document.getElementById('add-game-modal');
  if (!modal) {
    console.error('Modal #add-game-modal non trouvée');
    return;
  }

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

  // Initialiser l'autocomplétion
  const gameInput = document.getElementById('add_game_name');
  const suggestionsContainer = document.getElementById('suggestions-container');
  const searchLoader = document.getElementById('search-loader');
  const detailsLoader = document.getElementById('details-loader');

  if (!gameInput) {
    console.error('Champ #add_game_name non trouvé dans le DOM');
    return;
  }
  if (!suggestionsContainer) {
    console.error('Conteneur #suggestions-container non trouvé dans le DOM');
    return;
  }

  const searchGames = debounce(async (query) => {
    console.log('Recherche de jeux avec query:', query);
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
      const response = await fetch(`/app/games/search?query=${encodeURIComponent(query)}`, {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      console.log('Réponse AJAX pour recherche:', response.status);
      if (!response.ok) {
        throw new Error(`Erreur HTTP: ${response.status}`);
      }
      const games = await response.json();
      console.log('Jeux reçus:', games);

      suggestionsContainer.innerHTML = '';
      if (games.length > 0) {
        suggestionsContainer.className = suggestionsContainer.className.replace('hidden', 'block');
        games.forEach(game => {
          const div = document.createElement('div');
          div.className = 'px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer';
          div.textContent = game.name + (game.year ? ` (${game.year})` : '');
          div.addEventListener('click', async () => {
            console.log('Sélection du jeu:', game.name);
            gameInput.value = game.name;
            document.getElementById('add_game_bggId').value = game.id;

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
              console.log('Réponse AJAX pour détails:', detailsResponse.status);
              if (!detailsResponse.ok) {
                throw new Error(`Erreur HTTP: ${detailsResponse.status}`);
              }
              const details = await detailsResponse.json();
              console.log('Détails reçus:', details);
              document.getElementById('add_game_imageUrl').value = details.image || '';
            } catch (error) {
              console.error('Erreur lors de la récupération des détails du jeu:', error);
              document.getElementById('add_game_imageUrl').value = '';
              window.showToast('Erreur lors de la récupération des détails du jeu.', 'error');
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
      console.error('Erreur lors de la recherche de jeux:', error);
      suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
      suggestionsContainer.innerHTML = '';
      window.showToast('Erreur lors de la recherche de jeux.', 'error');
    } finally {
      if (searchLoader) {
        searchLoader.className = searchLoader.className.replace('block', 'hidden');
      }
    }
  }, 300);

  gameInput.addEventListener('input', () => {
    const query = gameInput.value;
    console.log('Input dans #add_game_name:', query);
    searchGames(query);
  });

  document.addEventListener('click', (e) => {
    if (!gameInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
      suggestionsContainer.className = suggestionsContainer.className.replace('block', 'hidden');
      suggestionsContainer.innerHTML = '';
    }
  });
}
