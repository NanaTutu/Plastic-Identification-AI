<?php
$classes = [
    ['code' => 'HDPE', 'name' => 'High-density polyethylene', 'uses' => 'Milk jugs, shampoo and detergent bottles', 'id' => 0],
    ['code' => 'LDPE', 'name' => 'Low-density polyethylene', 'uses' => 'Plastic bags, squeeze bottles, film packaging', 'id' => 1],
    ['code' => 'PVC', 'name' => 'Polyvinyl chloride', 'uses' => 'Pipes, fittings, flooring, window frames', 'id' => 2],
    ['code' => 'PET', 'name' => 'Polyethylene terephthalate', 'uses' => 'Drink bottles, food trays, textile fibre', 'id' => 3],
    ['code' => 'PP', 'name' => 'Polypropylene', 'uses' => 'Caps, lids, food containers, straws', 'id' => 4],
    ['code' => 'PS', 'name' => 'Polystyrene', 'uses' => 'Cutlery, cups, foam packaging', 'id' => 5],
];
?>
<div class="resin-grid">
    <?php foreach ($classes as $c): ?>
        <div class="resin-tile">
            <div>
                <code><?= esc($c['code']) ?></code>
                <h3><?= esc($c['name']) ?></h3>
                <p><?= esc($c['uses']) ?></p>
            </div>
            <span class="meta">#<?= $c['id'] ?></span>
        </div>
    <?php endforeach; ?>
</div>