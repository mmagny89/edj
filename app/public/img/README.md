# Images servies par le site

Deux fichiers ne servent qu'au PDF de la carte d'adherent et sont **derives**
du logo, decoupes une fois pour toutes :

| Fichier | Origine |
|---|---|
| `edj-mot-carte.png` | `edj-logo-transparent.png`, fenetre (0, 150) – (670, 480) puis rognage automatique des bords transparents |

Le mot-symbole est repris en image, et non compose en texte, parce que la
police du logo n'est pas celle du site et n'est pas distribuee avec le projet :
seule l'image porte le dessin exact des lettres. Le reste de la carte
(« Carte d'adherent », le verso) est du texte, dans la police d'affichage du
site — voir `public/fonts/README.md`.

A regenerer si le logo change :

```sh
docker compose exec php php -r '
$src = imagecreatefrompng("/app/public/img/edj-logo-transparent.png");
$box = imagecreatetruecolor(670, 330);
imagealphablending($box, false); imagesavealpha($box, true);
imagefill($box, 0, 0, imagecolorallocatealpha($box, 0, 0, 0, 127));
imagecopy($box, $src, 0, 0, 0, 150, 670, 330);
imagepng(imagecropauto($box, IMG_CROP_SIDES), "/app/public/img/edj-mot-carte.png");'
```
