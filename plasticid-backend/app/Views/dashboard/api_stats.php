<h2>API Platform Statistics</h2>

<p><strong>Total API Keys:</strong> <?= esc($stats['total_api_keys']) ?></p>
<p><strong>Active Keys:</strong> <?= esc($stats['active_keys']) ?></p>
<p><strong>Total Requests:</strong> <?= esc($stats['total_requests']) ?></p>

<h3>Top API Users</h3>

<table border="1">
<tr>
<th>API Key</th>
<th>Total Requests</th>
</tr>

<?php foreach ($stats['top_users'] as $user): ?>
<tr>
<td><?= esc($user['api_key']) ?></td>
<td><?= esc($user['total_requests']) ?></td>
</tr>
<?php endforeach; ?>

</table>