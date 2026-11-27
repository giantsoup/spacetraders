<?php

namespace App\Gameplay;

/** Roll back a provisional reservation change before settling the purchase. */
final class PurchaseReservationVeto extends GameException {}
