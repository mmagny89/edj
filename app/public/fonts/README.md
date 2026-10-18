# Polices embarquees dans les PDF

Ce dossier ne sert **pas** au site : le navigateur recoit les polices par
Webpack Encore, depuis les paquets `@fontsource` (`public/build/fonts/`).

Il sert a Dompdf, qui genere la carte d'adherent et ne lit ni WOFF ni WOFF2 :
il lui faut du TrueType. `akaya-kanadaka.ttf` est donc la version TTF de
`@fontsource/akaya-kanadaka` (fichier `akaya-kanadaka-latin-ext-400-normal.woff`
decompresse vers un sfnt standard), pour que le recto de la carte porte la meme
police d'affichage que le titre du site.

Licence : SIL Open Font License 1.1, reproduite dans
`akaya-kanadaka.LICENSE.txt`. A regenerer si le paquet npm change de version.
