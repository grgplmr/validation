# Validation multi-modérateurs pour articles

Ce plugin ajoute un indicateur de validation pour les articles WordPress : les administrateurs et éditeurs peuvent approuver un article via une metabox, et un aperçu des validations apparaît dans la liste des articles.

## Fonctionnalités
- Metabox de validation dans l’édition d’article avec comptage des validations et seuil requis.
- Bouton d’approbation/retrait pour les administrateurs et éditeurs.
- Colonne « Validations » dans la liste des articles avec codes couleur (vert, orange, rouge) selon l’état.
- Calcul automatique du seuil : 50 % des modérateurs (administrateurs + éditeurs), arrondi à l’entier supérieur.
- Aucun blocage de publication : uniquement des indicateurs visuels.

## Installation
1. Copier le dossier du plugin dans `wp-content/plugins/` sur votre site WordPress.
2. Activer **Validation multi-modérateurs pour articles** depuis le menu Extensions de l’administration WordPress.

## Utilisation
- Éditez un article : la metabox « Validations des modérateurs » affiche le nombre de validations, le seuil requis et le détail par modérateur. Les administrateurs et éditeurs peuvent approuver ou retirer leur approbation via le bouton dédié.
- Dans la liste des articles, la colonne « Validations » montre l’état global (seuil atteint, partiel ou aucun) avec un code couleur.
- Le plugin n’empêche pas la publication : il fournit uniquement un indicateur visuel du niveau de validation par les modérateurs.
