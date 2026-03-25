<h2>API Keys</h2>

<table border="1">
<tr>
<th>Owner</th>
<th>API Key</th>
<th>Rate Limit</th>
<th>Window</th>
<th>Total Requests</th>
<th>Status</th>
</tr>

<?php foreach ($keys as $k): ?>

<tr>
<td><?= esc($k['owner']) ?></td>
<td><?= esc($k['api_key']) ?></td>
<td><?= esc($k['rate_limit']) ?></td>
<td><?= esc($k['window_seconds']) ?> sec</td>
<td><?= esc($k['total_requests']) ?></td>
<td><?= $k['is_active'] ? 'Active' : 'Disabled' ?></td>
</tr>

<?php endforeach; ?>

</table>