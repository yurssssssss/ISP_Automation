<?php

// Vercel's PHP runtime only runs files in api/; hand every request to Laravel.
require __DIR__.'/../public/index.php';
