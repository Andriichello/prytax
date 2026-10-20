<?php

/*
 * Merged into api-core's config/pagination.php, so this file holds only what PryTax changes: pages of 20 by default
 * and of 50 at most, which the infinite scroll of the lists asks for (design.md §10).
 *
 * @see vendor/andriichello/api-core/docs/pagination/00-paginating-lists.md
 */
return [
    'max_results' => 50,
    'default_size' => 20,
];
