<?php
/**
 * Config: country_centroids
 *
 * Static lat/lng centroid per country name, used only to place a real
 * marker on the Advanced Analytics Geographic Distribution map
 * (enhancement spec section 11). universities.country (migration 004)
 * is free-text VARCHAR entered at signup, so this is a lookup, not an
 * enum — AdvancedAnalyticsRepository::geographicDistribution() returns
 * whatever country strings actually exist in the data. A country name
 * that isn't in this list simply doesn't get a map marker (it still
 * appears in the table beneath the map) rather than being given a
 * fabricated coordinate.
 *
 * Keys are matched case-insensitively against the DB value by the view.
 * @package UIP
 */

return [
    // MENA (the platform's primary region)
    'egypt' => [26.8206, 30.8025],
    'saudi arabia' => [23.8859, 45.0792],
    'united arab emirates' => [23.4241, 53.8478],
    'uae' => [23.4241, 53.8478],
    'qatar' => [25.3548, 51.1839],
    'kuwait' => [29.3117, 47.4818],
    'bahrain' => [26.0667, 50.5577],
    'oman' => [21.4735, 55.9754],
    'jordan' => [30.5852, 36.2384],
    'lebanon' => [33.8547, 35.8623],
    'syria' => [34.8021, 38.9968],
    'iraq' => [33.2232, 43.6793],
    'palestine' => [31.9522, 35.2332],
    'yemen' => [15.5527, 48.5164],
    'libya' => [26.3351, 17.2283],
    'tunisia' => [33.8869, 9.5375],
    'algeria' => [28.0339, 1.6596],
    'morocco' => [31.7917, -7.0926],
    'sudan' => [12.8628, 30.2176],

    // Rest of world (major countries a real signup might list)
    'united states' => [37.0902, -95.7129],
    'usa' => [37.0902, -95.7129],
    'united kingdom' => [55.3781, -3.4360],
    'uk' => [55.3781, -3.4360],
    'canada' => [56.1304, -106.3468],
    'germany' => [51.1657, 10.4515],
    'france' => [46.6034, 1.8883],
    'spain' => [40.4637, -3.7492],
    'italy' => [41.8719, 12.5674],
    'turkey' => [38.9637, 35.2433],
    'china' => [35.8617, 104.1954],
    'india' => [20.5937, 78.9629],
    'pakistan' => [30.3753, 69.3451],
    'malaysia' => [4.2105, 101.9758],
    'indonesia' => [-0.7893, 113.9213],
    'south africa' => [-30.5595, 22.9375],
    'nigeria' => [9.0820, 8.6753],
    'kenya' => [-0.0236, 37.9062],
    'brazil' => [-14.2350, -51.9253],
    'russia' => [61.5240, 105.3188],
    'japan' => [36.2048, 138.2529],
    'south korea' => [35.9078, 127.7669],
    'australia' => [-25.2744, 133.7751],
];
