<?php
require_once 'includes/header.php';

$feedback = '';
$edit_data = null;

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_msg'])) {
    $id = intval($_POST['msg_id']);
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $type = $_POST['type'];
    $show_as_popup = isset($_POST['show_as_popup']) ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE site_messages SET title=?, message=?, type=?, show_as_popup=? WHERE id=?");
    $stmt->bind_param("sssii", $title, $message, $type, $show_as_popup, $id);
    if ($stmt->execute()) {
        header("Location: messages.php?updated=1");
        exit();
    } else {
        $feedback = "<div style='color: red; margin-bottom: 10px;'>Error updating message.</div>";
    }
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_msg'])) {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $type = $_POST['type'];
    $show_as_popup = isset($_POST['show_as_popup']) ? 1 : 0;
    
    // Ensure table has the new column
    $conn->query("ALTER TABLE site_messages ADD COLUMN IF NOT EXISTS show_as_popup TINYINT(1) DEFAULT 0");

    $stmt = $conn->prepare("INSERT INTO site_messages (title, message, type, is_active, show_as_popup) VALUES (?, ?, ?, 1, ?)");
    $stmt->bind_param("sssi", $title, $message, $type, $show_as_popup);
    if ($stmt->execute()) {
        $feedback = "<div style='color: green; margin-bottom: 10px;'>Message published successfully.</div>";
    } else {
        $feedback = "<div style='color: red; margin-bottom: 10px;'>Error publishing message.</div>";
    }
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM site_messages WHERE id=$id");
    header("Location: messages.php");
    exit();
}

if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $conn->query("UPDATE site_messages SET is_active = NOT is_active WHERE id=$id");
    header("Location: messages.php");
    exit();
}

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM site_messages WHERE id=$id");
    if($res->num_rows > 0) {
        $edit_data = $res->fetch_assoc();
    }
}

if (isset($_GET['updated'])) {
    $feedback = "<div style='color: green; margin-bottom: 10px;'>Message updated successfully.</div>";
}

$msgs = $conn->query("SELECT * FROM site_messages ORDER BY created_at DESC");
?>

<div class="card">
    <h2><?php echo $edit_data ? 'Edit Message' : 'Broadcast Messages'; ?></h2>
    <?php echo $feedback; ?>
    <form method="POST" action="messages.php">
        <?php if($edit_data): ?>
            <input type="hidden" name="msg_id" value="<?php echo $edit_data['id']; ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" class="form-control" required placeholder="e.g. System Maintenance" value="<?php echo $edit_data ? htmlspecialchars($edit_data['title']) : ''; ?>">
        </div>
        <div class="form-group">
            <label>Message Content</label>
            <textarea name="message" class="form-control" rows="4" required placeholder="Details..."><?php echo $edit_data ? htmlspecialchars($edit_data['message']) : ''; ?></textarea>
        </div>
        <div class="form-group">
            <label>Type</label>
            <select name="type" class="form-control">
                <option value="info" <?php echo ($edit_data && $edit_data['type'] == 'info') ? 'selected' : ''; ?>>Info (Blue)</option>
                <option value="warning" <?php echo ($edit_data && $edit_data['type'] == 'warning') ? 'selected' : ''; ?>>Warning (Yellow)</option>
                <option value="maintenance" <?php echo ($edit_data && $edit_data['type'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance (Red)</option>
                <option value="success" <?php echo ($edit_data && $edit_data['type'] == 'success') ? 'selected' : ''; ?>>Success (Green)</option>
            </select>
        </div>
        
        <div class="form-group" style="display:flex; align-items:center; gap:10px; margin-bottom:15px; background:#f8f9fa; padding:10px; border-radius:5px;">
            <input type="checkbox" id="popupCheck" name="show_as_popup" value="1" <?php echo ($edit_data && isset($edit_data['show_as_popup']) && $edit_data['show_as_popup']) ? 'checked' : ''; ?> style="width:auto; transform:scale(1.2);">
            <div>
                <label for="popupCheck" style="margin:0; font-weight:600; cursor:pointer;">Show as Popup / Reminder</label>
                <div style="font-size:0.85em; color:#6c757d;">If checked, this message will appear as a modal popup to users (good for urgent maintenance alerts).</div>
            </div>
        </div>

        <?php if($edit_data): ?>
            <button type="submit" name="update_msg" class="btn btn-success"><i class="fas fa-save"></i> Update Message</button>
            <a href="messages.php" class="btn btn-danger" style="display:inline-flex;">Cancel</a>
        <?php else: ?>
            <button type="submit" name="add_msg" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publish Message</button>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>Message History</h3>
    <div class="table-responsive">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Title</th>
                <th>Type</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $msgs->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['created_at']; ?></td>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($row['title']); ?></div>
                    <div style="font-size:0.85em; color:var(--text-muted);"><?php echo substr(htmlspecialchars($row['message']), 0, 50) . '...'; ?></div>
                    <?php if(isset($row['show_as_popup']) && $row['show_as_popup']): ?>
                        <span style="font-size:0.75em; background:#6f42c1; color:white; padding:1px 4px; border-radius:3px; margin-top:2px; display:inline-block;"><i class="fas fa-window-restore"></i> Popup</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php 
                        $badgeClass = 'badge-info';
                        if($row['type'] == 'warning') $badgeClass = 'badge-warning';
                        if($row['type'] == 'maintenance') $badgeClass = 'badge-danger';
                        if($row['type'] == 'success') $badgeClass = 'badge-success';
                    ?>
                    <span class="badge <?php echo $badgeClass; ?>">
                        <?php echo ucfirst($row['type']); ?>
                    </span>
                </td>
                <td>
                    <?php echo $row['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Inactive</span>'; ?>
                </td>
                <td>
                    <a href="messages.php?toggle=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm">Toggle Status</a>
                    <a href="messages.php?edit=<?php echo $row['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-edit"></i></a>
                    <a href="messages.php?delete=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?');"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
