<?php
defined('ABSPATH') || exit;

/* paste the whole consolidated code here:
   - account menu filter
   - endpoint registration
   - page renderer (HTML/CSS/JS)
   - AJAX handlers for dept/branch/employee
   - import/export/sample CSV
   (keep the eppdp_title_case() exists-check)
*/



/**
 * ------------------------------------------------------------
 * My Account → Employees manager (Customers+)
 * - Adds the "Employees" tab (before Logout; keeps "Shop Now")
 * - Employees (full width), then Departments & Branches (2 cols)
 * - Polished Import/Export (2 cols) with sample CSV + handlers
 * - Uses user meta: employees, employee_departments, employee_branches
 * ------------------------------------------------------------
 */

function eppdp_sanitize_empcode($raw)
{
  $v = sanitize_text_field($raw);
  // Allow numbers, letters, dash, underscore, and keep leading zeros (e.g., "02")
  $v = preg_replace('/[^A-Za-z0-9_\-]/', '', $v ?? '');
  return $v;
}


/** 1) Add "Employees" before Logout (keeps your "Shop Now" entry too) */
add_filter('woocommerce_account_menu_items', function ($items) {
    $new = [];
    foreach ($items as $key => $label) {
        if ($key === 'customer-logout') {
            // keep Shop Now slot if present
            $new['shop-now-custom'] = isset($items['shop-now-custom']) ? $items['shop-now-custom'] : 'Shop Now';
            $new['employee'] = __('Employees', 'woocommerce');
        }
        $new[$key] = $label;
    }
    return $new;
}, 99);

/** 2) Endpoint (slug: employee) */
add_action('init', function () {
    add_rewrite_endpoint('employee', EP_ROOT | EP_PAGES);
});

/** 3) Render the Employees page */
add_action('woocommerce_account_employee_endpoint', function () {
    if (!is_user_logged_in() || !current_user_can('read')) {
        echo esc_html__('You do not have permission to access this page.', 'woocommerce');
        return;
    }

    $uid = get_current_user_id();
    $departments = get_user_meta($uid, 'employee_departments', true);
    $branches = get_user_meta($uid, 'employee_branches', true);
    $employees = get_user_meta($uid, 'employees', true);

    $departments = is_array($departments) ? $departments : [];
    $branches = is_array($branches) ? $branches : [];
    $employees = is_array($employees) ? $employees : [];

    $nonce_mgmt = wp_create_nonce('eppdp_emp_mgmt'); // CRUD
    $nonce_ie = wp_create_nonce('eppdp_emp_ie');   // import/export
    $ajax = admin_url('admin-ajax.php');
    ?>
        <style>
          /* base cards / look */
          .eppdp-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
          .eppdp-card h3,.eppdp-card h4{margin:.2rem 0 1rem}
          .eppdp-muted{color:#555;font-size:13px}
          .eppdp-field{margin:0 0 10px}
          .eppdp-field label{display:block;font-weight:600;margin-bottom:4px}
          .eppdp-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
          @media (max-width: 782px){.eppdp-grid2{grid-template-columns:1fr}}

          table.eppdp-table{width:100%;border-collapse:collapse}
          table.eppdp-table th, table.eppdp-table td{padding:10px;border-bottom:1px solid #eee;text-align:left}
          table.eppdp-table thead th{background:#f8fafc}

          /* rows: employees full width, then 2-col depts/branches, then 2-col import/export */
          .eppdp-row{display:block;margin-bottom:24px}
          .eppdp-row-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;margin-bottom:24px}
          @media (max-width: 992px){.eppdp-row-2{grid-template-columns:1fr}}

          /* lists */
          .eppdp-list{list-style:none;margin:0;padding:0}
          .eppdp-list li{display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;margin-bottom:8px;background:#fafafa}

          /* edit modal */
          #eppdp-edit-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:10000;align-items:center;justify-content:center}
          .eppdp-modal-inner{background:#fff;border-radius:10px;max-width:520px;width:92%;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.2)}

          
          .eppdp-ie{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;margin-top:0}
          @media (max-width:782px){.eppdp-ie{grid-template-columns:1fr}}
          .eppdp-actions .button{margin-right:8px}
          .eppdp-card .eppdp-actions{display:flex;flex-direction:column;gap:10px}
          .eppdp-drop{position:relative;border:2px dashed #cbd5e1;border-radius:10px;padding:18px;text-align:center;transition:background .2s ease}
          .eppdp-drop.is-dragover{background:#f8fafc}
          .eppdp-file{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2;width:100%;height:100%}
          .eppdp-drop-cta{position:relative;z-index:1;pointer-events:none}
          #eppdp-import-status{margin-left:8px;font-size:13px}
          .eppdp-help code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
          #eppdp-choose{visibility:hidden;height:0;padding:0!important;margin:0!important}
          .employee-wrapper select#eppdp-emp-dept, .employee-wrapper select#eppdp-emp-branch {
                min-height: 46px !important;
                padding-top: 10px;
                padding-bottom: 10px;
            }
            #eppdp-add-emp button.button.button-primary {
                height: 46px;
                line-height: 2;
            }
            .eppdp-table td .button {
                display: inline-block;
                margin-right: 6px;
            }

            .eppdp-table td .button:last-child {
                margin-right: 0;
            }
            .employee-wrapper button {
                cursor: pointer;
            }
            .eppdp-field.eppdp-edit-actions {
                display: flex;
                gap: 10px;
            }
            #eppdp-edit-emp input, #eppdp-edit-emp select {
                width: 95%;
            }
            button.button {
                cursor: pointer;
            }
        </style>

        <div class="employee-wrapper">
          <h2><?php esc_html_e('Manage Employees', 'woocommerce'); ?></h2>

          <!-- Row 1: Employees (full width) -->
          <div class="eppdp-row">
            <div class="eppdp-card">
              <h3><?php esc_html_e('Employees', 'woocommerce'); ?></h3>

              <form id="eppdp-add-emp" class="eppdp-grid2">
                <div class="eppdp-field">
                  <label for="eppdp-emp-name"><?php esc_html_e('Name', 'woocommerce'); ?></label>
                  <input type="text" id="eppdp-emp-name" required class="input-text"placeholder="<?php esc_attr_e('John Wick', 'woocommerce'); ?>">
                </div>

                <div class="eppdp-field">
                  <label for="eppdp-emp-code"><?php esc_html_e('Employee ID (optional)', 'woocommerce'); ?></label>
                  <input type="text" id="eppdp-emp-code" class="input-text"
                    placeholder="<?php esc_attr_e('e.g. 01 or EMP-001', 'woocommerce'); ?>">
                </div>

                <div class="eppdp-field">
                  <label for="eppdp-emp-dept"><?php esc_html_e('Department', 'woocommerce'); ?></label>
                  <select id="eppdp-emp-dept" required class="input-select">
                    <option value=""><?php esc_html_e('Select Department', 'woocommerce'); ?></option>
                    <?php foreach ($departments as $dept): ?>
                          <option value="<?php echo esc_attr($dept); ?>"><?php echo esc_html($dept); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eppdp-field">
                  <label for="eppdp-emp-branch"><?php esc_html_e('Branch', 'woocommerce'); ?></label>
                  <select id="eppdp-emp-branch" required class="input-select">
                    <option value=""><?php esc_html_e('Select Branch', 'woocommerce'); ?></option>
                    <?php foreach ($branches as $br): ?>
                          <option value="<?php echo esc_attr($br); ?>"><?php echo esc_html($br); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eppdp-field" style="align-self:end">
                  <button class="button button-primary"><?php esc_html_e('Add Employee', 'woocommerce'); ?></button>
                </div>
              </form>

              <h4 style="margin-top:8px"><?php esc_html_e('Employee List', 'woocommerce'); ?></h4>
              <table class="eppdp-table" id="eppdp-emp-table">
                <thead>
                  <tr>
                    <th><?php esc_html_e('Name', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('ID', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Department', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Branch', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Actions', 'woocommerce'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($employees as $idx => $e): ?>
                        <tr data-id="<?php echo esc_attr($idx); ?>">
                          <td><?php echo esc_html($e['name'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['code'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['department'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['branch'] ?? ''); ?></td>
                          <td>
                            <button class="button edit-emp" data-id="<?php echo esc_attr($idx); ?>"><?php esc_html_e('Edit', 'woocommerce'); ?></button>
                            <button class="button delete-emp" data-id="<?php echo esc_attr($idx); ?>"><?php esc_html_e('Delete', 'woocommerce'); ?></button>
                          </td>
                        </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <div id="eppdp-messages" class="eppdp-muted" style="margin-top:8px;"></div>
            </div>
          </div>

          <!-- Row 2: Departments + Branches (two columns) -->
          <div class="eppdp-row-2">
            <div class="eppdp-card">
              <h3><?php esc_html_e('Departments', 'woocommerce'); ?></h3>
              <form id="eppdp-add-dept">
                <div class="eppdp-field">
                  <label for="eppdp-dept-name"><?php esc_html_e('Department Name', 'woocommerce'); ?></label>
                  <input type="text" id="eppdp-dept-name" required class="input-text">
                </div>
                <button class="button"><?php esc_html_e('Add Department', 'woocommerce'); ?></button>
              </form>

              <h4 style="margin-top:16px"><?php esc_html_e('Department List', 'woocommerce'); ?></h4>
              <ul id="eppdp-dept-list" class="eppdp-list">
                <?php foreach ($departments as $i => $dept): ?>
                      <li data-id="<?php echo esc_attr($i); ?>">
                        <span><?php echo esc_html($dept); ?></span>
                        <button class="button delete-dept" data-id="<?php echo esc_attr($i); ?>"><?php esc_html_e('Delete', 'woocommerce'); ?></button>
                      </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="eppdp-card">
              <h3><?php esc_html_e('Branches', 'woocommerce'); ?></h3>
              <form id="eppdp-add-branch">
                <div class="eppdp-field">
                  <label for="eppdp-branch-name"><?php esc_html_e('Branch Name', 'woocommerce'); ?></label>
                  <input type="text" id="eppdp-branch-name" required class="input-text">
                </div>
                <button class="button"><?php esc_html_e('Add Branch', 'woocommerce'); ?></button>
              </form>

              <h4 style="margin-top:16px"><?php esc_html_e('Branch List', 'woocommerce'); ?></h4>
              <ul id="eppdp-branch-list" class="eppdp-list">
                <?php foreach ($branches as $i => $br): ?>
                      <li data-id="<?php echo esc_attr($i); ?>">
                        <span><?php echo esc_html($br); ?></span>
                        <button class="button delete-branch" data-id="<?php echo esc_attr($i); ?>"><?php esc_html_e('Delete', 'woocommerce'); ?></button>
                      </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>

          <!-- Row 3: Import / Export (two columns, polished) -->
          <div class="eppdp-ie">
            <div class="eppdp-card">
              <h4><?php esc_html_e('Export', 'woocommerce'); ?></h4>
              <p class="eppdp-muted">
                <?php esc_html_e('Download your employees as CSV (columns:', 'woocommerce'); ?>
                <code>name</code>, <code>department</code>, <code>branch</code>).
              </p>
              <div class="eppdp-actions">
                <a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'eppdp_export_employees', 'nonce' => $nonce_ie], $ajax)); ?>">
                  <?php esc_html_e('Export CSV', 'woocommerce'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'eppdp_sample_employees_csv', 'nonce' => $nonce_ie], $ajax)); ?>">
                  <?php esc_html_e('Download sample CSV', 'woocommerce'); ?>
                </a>
              </div>
            </div>

            <div class="eppdp-card">
              <h4><?php esc_html_e('Import', 'woocommerce'); ?></h4>
              <form id="eppdp-import-form" enctype="multipart/form-data">
                <div class="eppdp-drop" id="eppdp-drop">
                  <input type="file" name="csv" id="eppdp-file" class="eppdp-file" accept=".csv,text/csv" required>
                  <div class="eppdp-drop-cta">
                    <strong id="eppdp-file-label"><?php esc_html_e('Drop your CSV here', 'woocommerce'); ?></strong><br>
                    <span class="eppdp-muted"><?php esc_html_e('or click to choose a file', 'woocommerce'); ?></span>
                  </div>
                </div>

                <p style="margin:8px 0 0;">
                  <button type="button" class="button" id="eppdp-choose"><?php esc_html_e('Choose CSV…', 'woocommerce'); ?></button>
                  <span class="eppdp-muted" id="eppdp-file-name"></span>
                </p>

                <p style="margin:12px 0 6px;"><strong><?php esc_html_e('Mode', 'woocommerce'); ?></strong></p>
                <label style="display:block;margin-bottom:6px;"><input type="radio" name="mode" value="append" checked> <?php esc_html_e('Append (skip duplicates)', 'woocommerce'); ?></label>
                <label style="display:block;margin-bottom:10px;"><input type="radio" name="mode" value="replace"> <?php esc_html_e('Replace current employees', 'woocommerce'); ?></label>

                <input type="hidden" name="action" value="eppdp_import_employees">
                <input type="hidden" name="nonce"  value="<?php echo esc_attr($nonce_ie); ?>">

                <button type="submit" class="button button-primary"><?php esc_html_e('Import', 'woocommerce'); ?></button>
                <span id="eppdp-import-status"></span>

                <p class="eppdp-help" style="margin-top:12px;">
                  <?php esc_html_e('Header row:', 'woocommerce'); ?> <code>name,department,branch</code><br>
                  <?php esc_html_e('Example:', 'woocommerce'); ?> <code>Jane Smith,Sales,Enfield</code>
                </p>
              </form>
            </div>
          </div>
        </div>

        <!-- Edit modal -->
        <div id="eppdp-edit-modal">
          <div class="eppdp-modal-inner">
            <h3 style="margin-top:0"><?php esc_html_e('Edit Employee', 'woocommerce'); ?></h3>
            <form id="eppdp-edit-emp" class="eppdp-grid2">
              <input type="hidden" id="eppdp-edit-id">
              <div class="eppdp-field">
                <label for="eppdp-edit-name"><?php esc_html_e('Name', 'woocommerce'); ?></label>
                <input type="text" id="eppdp-edit-name" required class="input-text">
              </div>

              <div class="eppdp-field">
                <label for="eppdp-edit-code"><?php esc_html_e('Employee ID (optional)', 'woocommerce'); ?></label>
                <input type="text" id="eppdp-edit-code" class="input-text"
                  placeholder="<?php esc_attr_e('e.g. 01 or EMP-001', 'woocommerce'); ?>">
              </div>

              <div class="eppdp-field">
                <label for="eppdp-edit-dept"><?php esc_html_e('Department', 'woocommerce'); ?></label>
                <select id="eppdp-edit-dept" required class="input-select">
                  <option value=""><?php esc_html_e('Select Department', 'woocommerce'); ?></option>
                  <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo esc_attr($dept); ?>"><?php echo esc_html($dept); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="eppdp-field">
                <label for="eppdp-edit-branch"><?php esc_html_e('Branch', 'woocommerce'); ?></label>
                <select id="eppdp-edit-branch" required class="input-select">
                  <option value=""><?php esc_html_e('Select Branch', 'woocommerce'); ?></option>
                  <?php foreach ($branches as $br): ?>
                        <option value="<?php echo esc_attr($br); ?>"><?php echo esc_html($br); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="eppdp-field eppdp-edit-actions" style="align-self:end">
                <button class="button button-primary"><?php esc_html_e('Update', 'woocommerce'); ?></button>
                <button type="button" class="button" id="eppdp-cancel-edit"><?php esc_html_e('Cancel', 'woocommerce'); ?></button>
              </div>
            </form>
          </div>
        </div>

        <script>
          jQuery(function($){
            var ajax = '<?php echo esc_js($ajax); ?>';
            var nonceMgmt = '<?php echo esc_js($nonce_mgmt); ?>';

            function msg(text, isError){
              $('#eppdp-messages').text(text).css('color', isError ? '#b91c1c' : '#111827');
              setTimeout(function(){ $('#eppdp-messages').text(''); }, 2000);
            }
            function rebuildOptions($sel, arr, placeholder){
              var html = '<option value="">' + placeholder + '</option>';
              (arr||[]).forEach(function(v){ html += '<option value="'+ $('<div>').text(v).html() +'">'+ $('<div>').text(v).html() +'</option>'; });
              $sel.html(html);
            }
            function rebuildList($ul, arr, delClass){
              $ul.empty();
              (arr||[]).forEach(function(v,i){
                $ul.append('<li data-id="'+i+'"><span>'+ $('<div>').text(v).html() +'</span><button class="button '+delClass+'" data-id="'+i+'"><?php echo esc_js(__('Delete', 'woocommerce')); ?></button></li>');
              });
            }
            function rebuildTable(list){
              var $tb = $('#eppdp-emp-table tbody').empty();
              (list||[]).forEach(function(e,i){
                $tb.append(
                  '<tr data-id="'+i+'">'+
                    '<td>'+ $('<div>').text(e.name||'').html() +'</td>'+
                    '<td>'+ $('<div>').text(e.code||'').html() +'</td>'+
                    '<td>'+ $('<div>').text(e.department||'').html() +'</td>'+
                    '<td>'+ $('<div>').text(e.branch||'').html() +'</td>'+
                    '<td>'+
                      '<button class="button edit-emp" data-id="'+i+'"><?php echo esc_js(__('Edit', 'woocommerce')); ?></button> '+
                      '<button class="button delete-emp" data-id="'+i+'"><?php echo esc_js(__('Delete', 'woocommerce')); ?></button>'+
                    '</td>'+
                  '</tr>'
                );
              });
            }

            /* Departments */
            $('#eppdp-add-dept').on('submit', function(e){
              e.preventDefault();
              var name = $('#eppdp-dept-name').val();
              $.post(ajax, {action:'eppdp_add_department', nonce:nonceMgmt, department_name:name}, function(res){
                if(res.success){
                  rebuildList($('#eppdp-dept-list'), res.data.departments, 'delete-dept');
                  rebuildOptions($('#eppdp-emp-dept'), res.data.departments, '<?php echo esc_js(__('Select Department', 'woocommerce')); ?>');
                  rebuildOptions($('#eppdp-edit-dept'), res.data.departments, '<?php echo esc_js(__('Select Department', 'woocommerce')); ?>');
                  $('#eppdp-dept-name').val('');
                  msg('<?php echo esc_js(__('Department added', 'woocommerce')); ?>');
                } else { msg(res.data && res.data.message || 'Error', true); }
              });
            });
            $(document).on('click','.delete-dept', function(){
              $.post(ajax, {action:'eppdp_delete_department', nonce:nonceMgmt, department_id:$(this).data('id')}, function(res){
                if(res.success){
                  rebuildList($('#eppdp-dept-list'), res.data.departments, 'delete-dept');
                  rebuildOptions($('#eppdp-emp-dept'), res.data.departments, '<?php echo esc_js(__('Select Department', 'woocommerce')); ?>');
                  rebuildOptions($('#eppdp-edit-dept'), res.data.departments, '<?php echo esc_js(__('Select Department', 'woocommerce')); ?>');
                  msg('<?php echo esc_js(__('Department deleted', 'woocommerce')); ?>');
                } else { msg(res.data && res.data.message || 'Error', true); }
              });
            });

            /* Branches */
            $('#eppdp-add-branch').on('submit', function(e){
              e.preventDefault();
              var name = $('#eppdp-branch-name').val();
              $.post(ajax, {action:'eppdp_add_branch', nonce:nonceMgmt, branch_name:name}, function(res){
                if(res.success){
                  rebuildList($('#eppdp-branch-list'), res.data.branches, 'delete-branch');
                  rebuildOptions($('#eppdp-emp-branch'), res.data.branches, '<?php echo esc_js(__('Select Branch', 'woocommerce')); ?>');
                  rebuildOptions($('#eppdp-edit-branch'), res.data.branches, '<?php echo esc_js(__('Select Branch', 'woocommerce')); ?>');
                  $('#eppdp-branch-name').val('');
                  msg('<?php echo esc_js(__('Branch added', 'woocommerce')); ?>');
                } else { msg(res.data && res.data.message || 'Error', true); }
              });
            });
            $(document).on('click','.delete-branch', function(){
              $.post(ajax, {action:'eppdp_delete_branch', nonce:nonceMgmt, branch_id:$(this).data('id')}, function(res){
                if(res.success){
                  rebuildList($('#eppdp-branch-list'), res.data.branches, 'delete-branch');
                  rebuildOptions($('#eppdp-emp-branch'), res.data.branches, '<?php echo esc_js(__('Select Branch', 'woocommerce')); ?>');
                  rebuildOptions($('#eppdp-edit-branch'), res.data.branches, '<?php echo esc_js(__('Select Branch', 'woocommerce')); ?>');
                  msg('<?php echo esc_js(__('Branch deleted', 'woocommerce')); ?>');
                } else { msg(res.data && res.data.message || 'Error', true); }
              });
            });

            /* Employees add/delete */
            $('#eppdp-add-emp').on('submit', function(e){
              e.preventDefault();
              var data = {
                action:'eppdp_add_employee', nonce:nonceMgmt,
                employee_name:  $('#eppdp-emp-name').val(),
                employee_code:       $('#eppdp-emp-code').val(),
                employee_department: $('#eppdp-emp-dept').val(),
                employee_branch:     $('#eppdp-emp-branch').val()
              };
              $.post(ajax, data, function(res){
                if(res && res.success){
                  rebuildTable(res.data.employees);
                  $('#eppdp-add-emp')[0].reset();
                  msg('<?php echo esc_js(__('Employee added', 'woocommerce')); ?>');
                } else {
                  var err = (res && res.data && res.data.message) ? res.data.message : 'Error';
                  msg(err, true);
                }
              });

            });
            $(document).on('click','.delete-emp', function(){
              $.post(ajax, {action:'eppdp_delete_employee', nonce:nonceMgmt, employee_id:$(this).data('id')}, function(res){
                if(res.success){ rebuildTable(res.data.employees); msg('<?php echo esc_js(__('Employee deleted', 'woocommerce')); ?>'); }
                else { msg(res.data && res.data.message || 'Error', true); }
              });
            });

            /* Edit employee modal */
            $(document).on('click','.edit-emp', function(){
              var id = $(this).data('id'), $row = $('tr[data-id="'+id+'"]');
              $('#eppdp-edit-id').val(id);
              $('#eppdp-edit-name').val($row.find('td').eq(0).text());
              $('#eppdp-edit-code').val($row.find('td').eq(1).text());
              $('#eppdp-edit-dept').val($row.find('td').eq(2).text());
              $('#eppdp-edit-branch').val($row.find('td').eq(3).text());
              $('#eppdp-edit-modal').css('display','flex');
            });
            $('#eppdp-cancel-edit').on('click', function(){ $('#eppdp-edit-modal').hide(); });
            $('#eppdp-edit-emp').on('submit', function(e){
              e.preventDefault();
              var data = {
                action:'eppdp_edit_employee', nonce:nonceMgmt,
                employee_id: $('#eppdp-edit-id').val(),
                employee_name: $('#eppdp-edit-name').val(),
                employee_code: $('#eppdp-edit-code').val(),
                employee_department: $('#eppdp-edit-dept').val(),
                employee_branch: $('#eppdp-edit-branch').val()
              };
              $.post(ajax, data, function(res){
                if(res.success){ rebuildTable(res.data.employees); $('#eppdp-edit-modal').hide(); msg('<?php echo esc_js(__('Employee updated', 'woocommerce')); ?>'); }
                else { msg(res.data && res.data.message || 'Error', true); }
              });
            });

            /* Import (overlay input so click always works) */
            var $file   = $('#eppdp-file'), $drop = $('#eppdp-drop'), $form = $('#eppdp-import-form');
            var $btn    = $form.find('button[type="submit"]'), $msg = $('#eppdp-import-status');
            var $choose = $('#eppdp-choose'), $fileName = $('#eppdp-file-name'), $fileLabel = $('#eppdp-file-label');
            function setChosenName(n){ $fileLabel.text(n || '<?php echo esc_js(__('Drop your CSV here', 'woocommerce')); ?>'); $fileName.text(n || ''); }
            $choose.on('click', function(){ $file.val(''); $file.trigger('click'); });
            $file.on('change', function(){ setChosenName(this.files && this.files.length ? this.files[0].name : ''); });
            $drop.on('drag dragstart dragend dragover dragenter dragleave drop', function(e){ e.preventDefault(); e.stopPropagation(); })
                 .on('dragover dragenter', function(){ $drop.addClass('is-dragover'); })
                 .on('dragleave dragend drop', function(){ $drop.removeClass('is-dragover'); });
            $form.on('submit', function(e){
              e.preventDefault();
              if (!$file[0].files.length){ $msg.text('<?php echo esc_js(__('Please choose a CSV file.', 'woocommerce')); ?>'); $file.trigger('click'); return; }
              $btn.prop('disabled', true); $msg.text('<?php echo esc_js(__('Uploading…', 'woocommerce')); ?>');
              var fd = new FormData($form[0]);
              $.ajax({ url: ajax, type:'POST', data: fd, processData:false, contentType:false })
                .done(function(res){
                  if(res && res.success){
                    $msg.text('<?php echo esc_js(__('Imported', 'woocommerce')); ?> ' + (res.data.count||0) + ' · <?php echo esc_js(__('Total', 'woocommerce')); ?> ' + (res.data.total||0) + '. <?php echo esc_js(__('Refreshing…', 'woocommerce')); ?>');
                    setTimeout(function(){ window.location.reload(); }, 700);
                  } else { $msg.text((res && res.data && res.data.message) ? res.data.message : 'Import failed.'); }
                })
                .fail(function(){ $msg.text('Import failed (network/server).'); })
                .always(function(){ $btn.prop('disabled', false); });
            });
          });
        </script>
        <?php
});

/** 4) AJAX handlers (Departments/Branches/Employees) */
add_action('wp_ajax_eppdp_add_department', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $name = sanitize_text_field($_POST['department_name'] ?? '');
    $list = get_user_meta($uid, 'employee_departments', true);
    $list = is_array($list) ? $list : [];
    if ($name === '')
        wp_send_json_error(['message' => 'Empty department']);
    if (!in_array($name, $list, true)) {
        $list[] = $name;
        update_user_meta($uid, 'employee_departments', $list);
    }
    wp_send_json_success(['departments' => $list]);
});

add_action('wp_ajax_eppdp_delete_department', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $id = intval($_POST['department_id'] ?? -1);
    $deps = get_user_meta($uid, 'employee_departments', true);
    $deps = is_array($deps) ? $deps : [];
    $emps = get_user_meta($uid, 'employees', true);
    $emps = is_array($emps) ? $emps : [];
    $name = $deps[$id] ?? '';
    foreach ($emps as $e) {
        if (($e['department'] ?? '') === $name) {
            wp_send_json_error(['message' => 'Cannot delete department; it is in use by an employee']);
        }
    }
    if ($name !== '') {
        unset($deps[$id]);
        $deps = array_values($deps);
        update_user_meta($uid, 'employee_departments', $deps);
    }
    wp_send_json_success(['departments' => $deps]);
});

add_action('wp_ajax_eppdp_add_branch', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $name = sanitize_text_field($_POST['branch_name'] ?? '');
    $list = get_user_meta($uid, 'employee_branches', true);
    $list = is_array($list) ? $list : [];
    if ($name === '')
        wp_send_json_error(['message' => 'Empty branch']);
    if (!in_array($name, $list, true)) {
        $list[] = $name;
        update_user_meta($uid, 'employee_branches', $list);
    }
    wp_send_json_success(['branches' => $list]);
});

add_action('wp_ajax_eppdp_delete_branch', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $id = intval($_POST['branch_id'] ?? -1);
    $brs = get_user_meta($uid, 'employee_branches', true);
    $brs = is_array($brs) ? $brs : [];
    $emps = get_user_meta($uid, 'employees', true);
    $emps = is_array($emps) ? $emps : [];
    $name = $brs[$id] ?? '';
    foreach ($emps as $e) {
        if (($e['branch'] ?? '') === $name) {
            wp_send_json_error(['message' => 'Cannot delete branch; it is in use by an employee']);
        }
    }
    if ($name !== '') {
        unset($brs[$id]);
        $brs = array_values($brs);
        update_user_meta($uid, 'employee_branches', $brs);
    }
    wp_send_json_success(['branches' => $brs]);
});

add_action('wp_ajax_eppdp_add_employee', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $emp = [
        'name' => sanitize_text_field($_POST['employee_name'] ?? ''),
        'code' => eppdp_sanitize_empcode($_POST['employee_code'] ?? ''),
        'department' => sanitize_text_field($_POST['employee_department'] ?? ''),
        'branch' => sanitize_text_field($_POST['employee_branch'] ?? ''),
    ];
    if ($emp['name'] === '' || $emp['department'] === '' || $emp['branch'] === '')
        wp_send_json_error(['message' => 'Missing fields']);
    $list = get_user_meta($uid, 'employees', true);
    $list = is_array($list) ? $list : [];

    // prevent duplicate *codes* if you want uniqueness (skip if not desired)
    if ($emp['code'] !== '') {
        foreach ($list as $e) {
            if (is_array($e) && !empty($e['code']) && strcasecmp($e['code'], $emp['code']) === 0) {
                wp_send_json_error(['message' => 'Employee code already exists.']);
            }
        }
    }

    $list[] = $emp;
    update_user_meta($uid, 'employees', $list);
    wp_send_json_success(['employees' => $list]);
});

add_action('wp_ajax_eppdp_delete_employee', function () {
    check_ajax_referer('eppdp_emp_mgmt', 'nonce');
    $uid = get_current_user_id();
    $id = intval($_POST['employee_id'] ?? -1);
    $list = get_user_meta($uid, 'employees', true);
    $list = is_array($list) ? $list : [];
    if (isset($list[$id])) {
        unset($list[$id]);
        $list = array_values($list);
        update_user_meta($uid, 'employees', $list);
    } else {
        wp_send_json_error(['message' => 'Employee not found']);
    }
    wp_send_json_success(['employees' => $list]);
});

add_action('wp_ajax_eppdp_edit_employee', function () {
  check_ajax_referer('eppdp_emp_mgmt', 'nonce');
  $uid = get_current_user_id();
  $idx = intval($_POST['employee_id'] ?? -1);

  $new = [
    'name' => sanitize_text_field($_POST['employee_name'] ?? ''),
    'code' => eppdp_sanitize_empcode($_POST['employee_code'] ?? ''),
    'department' => sanitize_text_field($_POST['employee_department'] ?? ''),
    'branch' => sanitize_text_field($_POST['employee_branch'] ?? ''),
  ];

  $list = get_user_meta($uid, 'employees', true);
  $list = is_array($list) ? $list : [];

  if (!isset($list[$idx])) {
    wp_send_json_error(['message' => 'Employee not found']);
  }

  // enforce unique codes across others
  if ($new['code'] !== '') {
      foreach ($list as $i => $e) {
          if ($i === $idx) continue;
          if (is_array($e) && !empty($e['code']) && strcasecmp($e['code'], $new['code']) === 0) {
              wp_send_json_error(['message' => 'Employee code already exists.']);
          }
      }
  }

  $list[$idx] = array_merge(
    ['code' => '', 'name' => '', 'department' => '', 'branch' => ''],
    $new
  );

  update_user_meta($uid, 'employees', $list);
  wp_send_json_success(['employees' => $list]);
});


/** 5) Import/Export (one set only) */
if (!function_exists('eppdp_title_case')) {
    function eppdp_title_case($str)
    {
        return function_exists('mb_convert_case') ? mb_convert_case($str, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($str));
    }
}

add_action('wp_ajax_eppdp_sample_employees_csv', function () {
    if (!is_user_logged_in() || !current_user_can('read'))
        wp_die('Forbidden', '', 403);
    check_ajax_referer('eppdp_emp_ie', 'nonce');
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employees-sample.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'department', 'branch']);
    fputcsv($out, ['Jane Smith', 'Sales', 'Enfield']);
    fputcsv($out, ['John Doe', 'Accounts', 'Cheshunt']);
    fputcsv($out, ['A. Brown', 'Purchasing', 'Head Office']);
    fclose($out);
    exit;
});

add_action('wp_ajax_eppdp_export_employees', function () {
    if (!is_user_logged_in() || !current_user_can('read'))
        wp_die('Forbidden', '', 403);
    check_ajax_referer('eppdp_emp_ie', 'nonce');
    $uid = get_current_user_id();
    $emps = get_user_meta($uid, 'employees', true);
    $emps = is_array($emps) ? $emps : [];
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=employees-' . $uid . '-' . gmdate('Ymd-His') . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'department', 'branch']);
    foreach ($emps as $e) {
        fputcsv($out, [$e['name'] ?? '', $e['department'] ?? '', $e['branch'] ?? '']);
    }
    fclose($out);
    exit;
});

add_action('wp_ajax_eppdp_import_employees', function () {
    if (!is_user_logged_in() || !current_user_can('read'))
        wp_send_json_error(['message' => 'Forbidden'], 403);
    check_ajax_referer('eppdp_emp_ie', 'nonce');
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name']))
        wp_send_json_error(['message' => 'No CSV uploaded']);
    if (($_FILES['csv']['size'] ?? 0) > 5 * 1024 * 1024)
        wp_send_json_error(['message' => 'CSV is too large (max 5MB).']);
    $mode = (isset($_POST['mode']) && $_POST['mode'] === 'replace') ? 'replace' : 'append';
    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$fh)
        wp_send_json_error(['message' => 'Unable to read uploaded file']);
    $hdr = fgetcsv($fh);
    if (!$hdr) {
        fclose($fh);
        wp_send_json_error(['message' => 'CSV has no header row']);
    }
    if (isset($hdr[0]))
        $hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', $hdr[0]);
    $map = [];
    foreach ($hdr as $i => $h) {
        $k = strtolower(trim($h));
        if (in_array($k, ['name', 'department', 'branch'], true))
            $map[$k] = $i;
    }
    foreach (['name', 'department', 'branch'] as $need) {
        if (!isset($map[$need])) {
            fclose($fh);
            wp_send_json_error(['message' => "Missing column: $need"]);
        }
    }
    $rows = [];
    while (($c = fgetcsv($fh)) !== false) {
        if (trim(implode('', array_map('strval', $c))) === '')
            continue;
        $name = trim((string) ($c[$map['name']] ?? ''));
        if ($name === '')
            continue;
        $dept = eppdp_title_case(trim((string) ($c[$map['department']] ?? '')));
        $br = eppdp_title_case(trim((string) ($c[$map['branch']] ?? '')));
        $rows[] = ['name' => $name, 'department' => $dept, 'branch' => $br];
    }
    fclose($fh);
    $uid = get_current_user_id();
    $existing = get_user_meta($uid, 'employees', true);
    $existing = is_array($existing) ? $existing : [];
    if ($mode === 'replace') {
        $employees = $rows;
    } else {
        $seen = [];
        foreach ($existing as $e) {
            $seen[strtolower(($e['name'] ?? '') . '|' . ($e['department'] ?? '') . '|' . ($e['branch'] ?? ''))] = 1;
        }
        $employees = $existing;
        foreach ($rows as $e) {
            $k = strtolower(($e['name'] ?? '') . '|' . ($e['department'] ?? '') . '|' . ($e['branch'] ?? ''));
            if (empty($seen[$k])) {
                $employees[] = $e;
                $seen[$k] = 1;
            }
        }
    }
    update_user_meta($uid, 'employees', $employees);

    $deps = get_user_meta($uid, 'employee_departments', true);
    $deps = is_array($deps) ? $deps : [];
    $brs = get_user_meta($uid, 'employee_branches', true);
    $brs = is_array($brs) ? $brs : [];
    foreach ($rows as $e) {
        if (($e['department'] ?? '') !== '' && !in_array($e['department'], $deps, true))
            $deps[] = $e['department'];
        if (($e['branch'] ?? '') !== '' && !in_array($e['branch'], $brs, true))
            $brs[] = $e['branch'];
    }
    $deps = array_values(array_unique($deps, SORT_REGULAR));
    $brs = array_values(array_unique($brs, SORT_REGULAR));
    update_user_meta($uid, 'employee_departments', $deps);
    update_user_meta($uid, 'employee_branches', $brs);

    wp_send_json_success(['count' => count($rows), 'total' => count($employees)]);
});