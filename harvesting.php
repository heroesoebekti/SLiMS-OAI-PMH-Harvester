<?php
/**
 * @Created by          : Heru Subekti (heroe.soebekti@gmail.com)
 * @Date                : 10/01/2026
 * @File name           : harvesting.php
 */

defined('INDEX_AUTH') or die('Direct access is not allowed!');

require SB.'admin/default/session.inc.php';
require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';

$can_read = utility::havePrivilege('master_file', 'r');
$can_write = utility::havePrivilege('master_file', 'w');

if (!$can_read) {
    die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
}

$mod = isset($_GET['mod']) ? $_GET['mod'] : 'bibliography';
$plugin_id = isset($_GET['id']) ? $_GET['id'] : '';

$base_uri = 'plugin_container.php?mod=' . $mod . ($plugin_id ? '&id=' . $plugin_id : '');

function deleteNodeBiblios($dbs, $node_id_pk) {
    $stmt_hb = $dbs->prepare("SELECT biblio_id FROM harvest_biblio WHERE node_id = ?");
    $stmt_hb->bind_param("i", $node_id_pk);
    $stmt_hb->execute();
    $res_hb = $stmt_hb->get_result();

    $biblio_ids = [];
    while ($row_hb = $res_hb->fetch_assoc()) {
        $bid = (int)$row_hb['biblio_id'];
        if ($bid > 0) {
            $biblio_ids[] = $bid;
        }
    }

    $deleted_count = count($biblio_ids);

    if ($deleted_count > 0) {
        $ids_string = implode(',', $biblio_ids);
        $dbs->query("DELETE FROM biblio WHERE biblio_id IN ({$ids_string})");
        $dbs->query("DELETE FROM search_biblio WHERE biblio_id IN ({$ids_string})");
        $dbs->query("DELETE FROM biblio_author WHERE biblio_id IN ({$ids_string})");
        $dbs->query("DELETE FROM biblio_topic WHERE biblio_id IN ({$ids_string})");
        $dbs->query("DELETE FROM item WHERE biblio_id IN ({$ids_string})");
    }

    $dbs->query("DELETE FROM harvest_biblio WHERE node_id = {$node_id_pk}");
    
    $stmt_rel = $dbs->prepare("DELETE FROM harvest_biblio_relation WHERE node_id = ?");
    $stmt_rel->bind_param("i", $node_id_pk);
    $stmt_rel->execute();

    return $deleted_count;
}

if (isset($_GET['action']) && $_GET['action'] == 'harvest') {
    include __DIR__ . '/harvesting_process.php';
    exit();
}

if (isset($_POST['saveData']) && $can_write) {
    $updateRecordID = isset($_POST['updateRecordID']) ? (int)$_POST['updateRecordID'] : 0;
    $node_name = trim($_POST['node_name'] ?? '');
    $oai_url = trim($_POST['oai_url'] ?? '');
    $node_type = trim($_POST['node_type'] ?? 'oai');
    $is_active = 1;

    if (empty($node_name) || empty($oai_url)) {
        utility::jsToastr(__('Error'), __('All required fields must be filled!'), 'error');
        exit();
    }

    try {
        $sql_op = new simbio_dbop($dbs);
        $data = array(
            'node_name' => $node_name,
            'oai_url' => $oai_url,
            'node_type' => $node_type,
            'is_active' => $is_active
        );

        if ($updateRecordID > 0) {
            $sql_op->update('harvest_nodes', $data, 'id = ' . $updateRecordID);
            $msg = __('Repository Node Successfully Updated');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $sql_op->insert('harvest_nodes', $data);
            $msg = __('New Repository Node Successfully Saved');
        }

        utility::jsToastr(__('Success'), $msg, 'success');
        echo '<script type="text/javascript">parent.jQuery("#mainContent").simbioAJAX("' . $base_uri . '");</script>';
    } catch (\Exception $e) {
        utility::jsToastr(__('Error'), __('Data FAILED to Save. Please Contact System Administrator') . "\nDEBUG : " . $e->getMessage(), 'error');
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actionType']) && $_POST['actionType'] === 'clear_biblio' && isset($_POST['node_id'])) {
    if (!($can_read && $can_write)) {
        die(__('Unauthorized access'));
    }

    $node_id_pk = (int)$_POST['node_id'];
    $stmt_node = $dbs->prepare("SELECT node_name FROM harvest_nodes WHERE id = ? LIMIT 1");
    $stmt_node->bind_param("i", $node_id_pk);
    $stmt_node->execute();
    $res_node = $stmt_node->get_result();

    if ($row_node = $res_node->fetch_assoc()) {
        $deleted_count = deleteNodeBiblios($dbs, $node_id_pk);
        echo __('Successfully cleared ' . $deleted_count . ' bibliographic catalog data for this node.');
    } else {
        echo __('Node not found.');
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['itemID']) && !empty($_POST['itemID']) && isset($_POST['itemAction'])) {
    if (!($can_read &&$can_write)) {
        die();
    }

    foreach ($_POST['itemID'] as$node_id_pk) {
        $node_id_pk = (int)$node_id_pk;
        deleteNodeBiblios($dbs, $node_id_pk);$dbs->query("DELETE FROM harvest_nodes WHERE id = {$node_id_pk}");
    }

    echo __('Selected nodes and all related bibliographic data successfully deleted');
    exit();
}

$add_url =$base_uri . '&action=detail';
?>

<div class="menuBox">
<div class="menuBoxInner masterFileIcon">
    <div class="per_title">
        <h2><?php echo __('OAI-PMH Harvesting'); ?></h2>
    </div>
    <div class="sub_section">    
      <div class="btn-group">
        <a href="<?php echo $add_url; ?>" class="btn btn-primary ajax-load"><?php echo __('Add New Repository'); ?></a>
        <a href="<?php echo $base_uri; ?>" class="btn btn-default ajax-load"><?php echo __('Repository List'); ?></a>
    </div>
    </div>
</div>
</div>

<div id="harvestLogModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
  <div class="modal-content" style="background:#fff; padding:20px; width:600px; border-radius:5px; position:relative;">
    <div id="logContainer">
        <span class="close-btn" id="closeModalBtn" title="<?php echo __('Close Log'); ?> (&times;)" style="float:right; cursor:pointer; font-weight:bold; font-size:1.2em;">&times;</span>
        <div class="per_title"><h5><?php echo __('Harvesting Log'); ?></h5></div>
        <pre id="harvestLog" style="background:#f4f4f4; padding:10px; height:300px; overflow-y:scroll; border:1px solid #ddd;"></pre>
    </div>
    <div id="modalStatus" style="margin-top:10px;">
        <?php echo __('Log Node'); ?>: <span id="logNodeName" style="font-weight: normal;"></span> | <?php echo __('Status'); ?>: <span id="logStatus" style="font-weight: normal;"><?php echo __('Idle'); ?></span>
    </div>
  </div>
</div>

<?php
if (isset($_GET['action']) &&$_GET['action'] === 'detail') {
    if (!$can_write) {
        die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
    }

    $edit_id = isset($_GET['id_item']) ? (int)$_GET['id_item'] : 0;
    $node_name = '';
    $oai_url = '';$node_type = 'oai';

    if ($edit_id > 0) {
        $stmt =$dbs->prepare("SELECT * FROM harvest_nodes WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $edit_id);$stmt->execute();
        $result =$stmt->get_result();
        if ($row =$result->fetch_assoc()) {
            $node_name =$row['node_name'];
            $oai_url =$row['oai_url'];
            $node_type =$row['node_type'];
        }
    }

    $form_action_url = $base_uri . '&action=detail' . ($edit_id > 0 ? '&id_item='.$edit_id : '');
    $form = new simbio_form_table_AJAX('mainForm', $form_action_url, 'post');
    $form->submit_button_attr = 'name="saveData" value="'.__('Save').'" class="s-btn btn btn-primary"';
    $form->table_attr = 'id="dataList" class="s-table table"';
    $form->table_header_attr = 'class="alterCell font-weight-bold"';
    $form->table_content_attr = 'class="alterCell2"';

    if ($edit_id > 0) {$form->edit_mode = true;
        $form->record_id =$edit_id;
        $form->record_title =$node_name;
        echo '<div class="infoBox">'.__('You are going to edit repository node').' : <b>'.$node_name.'</b> (ID: '.$edit_id.')</div>';
        $form->addHidden('updateRecordID',$edit_id);
    } else {
        echo '<div class="infoBox">'.__('Adding new repository node to the system.').'</div>';
        $form->addHidden('updateRecordID', 0);
    }
    
    $form->addTextField('text', 'node_name', __('Library Name').'*',$node_name, 'class="form-control col-12" required');
    $form->addTextField('text', 'oai_url', __('OAI URL').'*',$oai_url, 'class="form-control col-12" required');
    $form->addSelectList('node_type', __('Type'), array(array('ojs', 'OJS'), array('slims', 'SLiMS')),$node_type, 'class="form-control col-md-3"');
    
    echo $form->printOut();

} else {
    $table = new simbio_table();
    $table->table_attr = 'align="center" class="s-table table" cellpadding="5" cellspacing="0"';
    echo '<div class="p-3">
    <input value="'.__('Delete Selected Data').'" class="button btn btn-danger btn-delete" type="button"> 
    <input value="'.__('Check All').'" class="check-all button btn btn-default" type="button"> 
    <input value="'.__('Uncheck All').'" class="uncheck-all button btn btn-default" type="button"></div>';

    $table->setHeader(array(__('DELETE'), __('EDIT'), __('Library Name'), __('OAI URL'), __('Type'), __('Action')));
    $table->table_header_attr = 'class="alterCell font-weight-bold"';

    $row = 1;
    $query =$dbs->query("SELECT * FROM harvest_nodes WHERE is_active = 1 ORDER BY node_name ASC");
    while ($data =$query->fetch_assoc()) {
        $node_id_pk =$data['id'];
        $node_name = htmlspecialchars($data['node_name']);
        $oai_url = htmlspecialchars($data['oai_url']);
        $node_type = htmlspecialchars($data['node_type']);

        $cb = '<input type="checkbox" name="itemID[]" value="'.$node_id_pk.'">';
        $edit_url = $base_uri . '&action=detail&id_item='.$node_id_pk;
        $link = '<a href="'.$edit_url.'" class="editLink ajax-load" title="'.__('Edit Node').'"></a>';
        $lib_info = '<strong>' . $node_name . '</strong>';$url_link = '<a href="' . $oai_url . '" target="_blank">' . $oai_url . '</a>';

        $action_btns = '<a href="#" class="notAJAX btn btn-default ajax-harvest-btn" 
            data-node-id="' . $node_id_pk . '" 
            data-node-name="' . $node_name . '" 
            data-mode="append"
            style="padding:3px 8px; margin-right:4px;" title="'.__('Harvest').'">' . __('Harvest') . '</a>' .
            '<a href="#" class="notAJAX btn btn-danger ajax-harvest-btn" 
            data-node-id="' . $node_id_pk . '" 
            data-node-name="' . $node_name . '" 
            data-mode="fresh"
            style="padding:3px 8px; margin-right:4px;" title="'.__('Re-Harvest').'">' . __('Re-Harvest') . '</a>' .
            '<a href="#" class="notAJAX btn btn-warning btn-clear-biblio" 
            data-node-pk="' . $node_id_pk . '" 
            data-node-name="' . $node_name . '" 
            style="padding:3px 8px; color:#fff;" title="'.__('Clear Biblio').'">' . __('Clear Biblio') . '</a>';

        $table->appendTableRow(array($cb,$link, $lib_info,$url_link, $node_type,$action_btns));
        
        $table->setCellAttr($row, 0, 'class="alterCell text-center" valign="top" style="width: 5px;"');
        $table->setCellAttr($row, 1, 'class="alterCell2 text-center" valign="top" style="width: 20px;"');
        $table->setCellAttr($row, 2, 'class="alterCell" valign="top" style="width: 20%;"');
        $table->setCellAttr($row, 3, 'class="alterCell" valign="top" style="width: 30%;"');
        $table->setCellAttr($row, 4, 'class="alterCell" valign="top" style="width: 10%;"');
        $table->setCellAttr($row, 5, 'class="alterCell" valign="top" style="width: 30%; text-align: center;"');
        $row++;
    }

    echo $table->printTable();
}
?>

<script type="text/javascript">
(function() {
    var $ = parent.jQuery || jQuery;
    var main_uri = '<?php echo $base_uri; ?>';

    $('#mainContent').off('click', 'a.ajax-load').on('click', 'a.ajax-load', function(e) {
        e.preventDefault();
        var targetUrl = $(this).attr('href');$('#mainContent').simbioAJAX(targetUrl);
    });

    $('.btn-delete').off('click').on('click', function (e) {
        var data = [];
        $("input[name='itemID[]']:checked").each(function() {
           data.push($(this).val());
        });
        if (data.length === 0) {
            alert("<?php echo __('Please select data to delete!'); ?>");
            return;
        }
        if (!confirm("<?php echo __('WARNING: Deleting the selected nodes will automatically delete all related bibliographic catalog data. Continue?'); ?>")) {
            return;
        }

        $.ajax({
            url: main_uri,
            type: 'post',
            data: { itemID: data, itemAction: true }
        })
        .done(function (msg) {
             alert(msg);
             $('#mainContent').simbioAJAX(main_uri);
        })
        .fail(function() {
            alert("<?php echo __('An error occurred while deleting data.'); ?>");
        });
    });

    $(document).off('click', '.btn-clear-biblio').on('click', '.btn-clear-biblio', function(e) {
        e.preventDefault();
        var $btn =$(this);
        var nodePk = $btn.data('node-pk');
        var nodeName = $btn.data('node-name');

        if (!confirm('<?php echo __('WARNING: All bibliographic catalog data from node'); ?> "' + nodeName + '" <?php echo __('will be deleted, but the node configuration will be preserved. Continue?'); ?>')) {
            return;
        }

        $btn.addClass('disabled').text('<?php echo __('Processing...'); ?>');

        $.ajax({
            url: main_uri,
            type: 'post',
            data: { actionType: 'clear_biblio', node_id: nodePk }
        })
        .done(function(msg) {
            alert(msg);
            $('#mainContent').simbioAJAX(main_uri);
        })
        .fail(function() {
            alert("<?php echo __('An error occurred while clearing bibliographic data.'); ?>");
            $btn.removeClass('disabled').text('<?php echo __('Clear Biblio'); ?>');
        });
    });

    $(".uncheck-all").off('click').on('click', function(e) {
        e.preventDefault();
        $('input[name="itemID[]"]').prop('checked', false);
    });

    $(".check-all").off('click').on('click', function(e) {
        e.preventDefault();
        $('input[name="itemID[]"]').prop('checked', true);
    });

    const PROCESS_URL = main_uri;
    let eventSource = null; 
    let modalDisplayed = false; 

    const $modal =$('#harvestLogModal', parent.document);
    const $log =$('#harvestLog', parent.document);
    const $logNodeName =$('#logNodeName', parent.document);
    const $closeBtn =$('#closeModalBtn', parent.document);
    const $logStatus =$('#logStatus', parent.document); 
    
    let $activeBtn = null;

    function appendLog(message) {
        const timestamp = new Date().toLocaleTimeString();
        $log.append(`[${timestamp}] ${message}\n`);
        $log.scrollTop($log[0].scrollHeight);
    }
    
    function setStatus(message) {
        $logStatus.text(message);
    }

    function closeModal() {
        if ($closeBtn.hasClass('disabled')) return; 

        $modal.hide();
        modalDisplayed = false;
        $log.empty();$logNodeName.text('');
        setStatus('<?php echo __('Idle'); ?>');
        
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        
        if ($activeBtn) {$activeBtn.removeClass('disabled').text($activeBtn.data('mode') === 'fresh' ? '<?php echo __('Re-Harvest'); ?>' : '<?php echo __('Harvest'); ?>');$activeBtn = null;
        }
        $('#mainContent').simbioAJAX(main_uri);
    }

    $(document).off('click', '.ajax-harvest-btn').on('click', '.ajax-harvest-btn', function(e) {
        e.preventDefault();
        const $btn =$(this);
        const nodeId = $btn.data('node-id');
        const nodeName = $btn.data('node-name');
        const mode = $btn.data('mode');

        if (mode === 'fresh') {
            if (!confirm('<?php echo __('All bibliographic data from node'); ?> "' + nodeName + '" <?php echo __('will be deleted and replaced with new data. Continue?'); ?>')) {
                return;
            }
        }

        if ($btn.hasClass('disabled')) return; 
        
        $activeBtn =$btn;
        
        $btn.addClass('disabled').text('<?php echo __('Connecting...'); ?>');
        $log.empty();$logNodeName.text(nodeName);
        setStatus('<?php echo __('Establishing Connection...'); ?>');
        
        $closeBtn.addClass('disabled'); 
        
        startServerSentEvents(nodeId, mode, PROCESS_URL);
    });
    
    $closeBtn.off('click').on('click', closeModal);

    function resetModalControls() {
        $closeBtn.removeClass('disabled'); 
        if ($activeBtn) {$activeBtn.removeClass('disabled').text('<?php echo __('Finished'); ?>');
        }
    }

    function startServerSentEvents(nodeId, mode, targetUrl) {
        const fullUrl = targetUrl + (targetUrl.indexOf('?') !== -1 ? '&' : '?') + 'action=harvest&node_id=' + encodeURIComponent(nodeId) + '&mode=' + encodeURIComponent(mode); 

        if (eventSource) eventSource.close();

        try {
            eventSource = new EventSource(fullUrl);
            
            eventSource.onopen = function() {
                if (!modalDisplayed) {
                    $modal.css('display', 'flex'); 
                    modalDisplayed = true;
                    appendLog(`--- <?php echo __('STARTING HARVEST'); ?> (${mode.toUpperCase()}) ---`);
                    setStatus('<?php echo __('Harvesting...'); ?>');
                }
            };

            eventSource.addEventListener('log', function(e) {
                if (!modalDisplayed) { $modal.css('display', 'flex'); modalDisplayed = true; }
                const data = JSON.parse(e.data);
                appendLog(data.message);
            });

            eventSource.addEventListener('finish', function(e) {
                const data = JSON.parse(e.data);
                appendLog(data.message);
                setStatus('<?php echo __('Finished. Total:'); ?> ' + (data.record_count || 0) + ' <?php echo __('records'); ?>');
                eventSource.close();
                eventSource = null;
                resetModalControls(); 
            });

            eventSource.onerror = function(err) {
                appendLog('<?php echo __('Error: Connection lost or server failure.'); ?>');
                if (eventSource) { eventSource.close(); eventSource = null; }
                resetModalControls();
            };
            
        } catch (e) {
            appendLog('<?php echo __('Error: Failed to initialize EventSource.'); ?>');
            resetModalControls(); 
        }
    }
})();
</script>