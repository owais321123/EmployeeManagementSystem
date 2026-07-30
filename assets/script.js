/* ============================================================
   Employee Management System — assets/script.js
   API-backed version: all data is read from and written to
   /api/*.php (backed by SQLite). No dummy data — every
   list starts empty until the API returns real records.
   ============================================================ */

(function () {
  'use strict';

  const API_BASE = 'api'; // api/*.php sits alongside index.html

  const state = {
    employees: [],
    departments: [],
    positions: [],
    salaries: [],
    leaves: [],
    pagination: {
      employees: { page: 1, perPage: 10, totalPages: 1 },
      departments: { page: 1, perPage: 10 }, // client-side paging (API returns full list)
      positions: { page: 1, perPage: 10 }
    },
    searchTerm: '',
    reportChart: null,
    confirmCallback: null
  };

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    setupNavigation();
    setupEmployeeSection();
    setupDepartmentSection();
    setupPositionSection();
    setupSalarySection();
    setupLeaveSection();
    setupReportsSection();
    setupSettingsSection();
    setupModalGenerics();
    setupConfirmationModal();
    setupToast();

    await Promise.all([
      loadDepartments(),
      loadPositions(),
      loadEmployees(),
      loadSalaries(),
      loadLeaves()
    ]);
    refreshAllSelects();
    await loadDashboard();
  }

  /* ---------------------------------------------------------
     API HELPER
  --------------------------------------------------------- */
  async function apiRequest(method, path, body) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin'
    };
    if (body !== undefined) opts.body = JSON.stringify(body);

    let res;
    try {
      res = await fetch(`${API_BASE}/${path}`, opts);
    } catch (err) {
      showToast('error', 'Network Error', 'Could not reach the server.');
      throw err;
    }

    if (res.status === 401) {
      window.location.href = 'login.php';
      throw new Error('Unauthorized');
    }

    let json;
    try {
      json = await res.json();
    } catch (err) {
      showToast('error', 'Server Error', 'Received an invalid response from the server.');
      throw err;
    }

    if (!json.success) {
      showToast('error', 'Error', json.error || 'Something went wrong.');
      throw new Error(json.error || 'Request failed');
    }

    return json.data;
  }

  /* ---------------------------------------------------------
     UTILITIES
  --------------------------------------------------------- */
  function formatCurrency(num) {
    const n = Number(num) || 0;
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
  }

  function formatStatusLabel(status) {
    const map = { active: 'Active', 'on-leave': 'On Leave', terminated: 'Terminated' };
    return map[status] || status;
  }

  function findEmployee(id) {
    return state.employees.find((e) => e.id === Number(id));
  }
  function findDepartment(id) {
    return state.departments.find((d) => d.id === Number(id));
  }
  function findPosition(id) {
    return state.positions.find((p) => p.id === Number(id));
  }
  function employeeFullName(emp) {
    if (!emp) return 'Unknown';
    return emp.employee_name || `${emp.first_name} ${emp.last_name}`;
  }

  /* ---------------------------------------------------------
     NAVIGATION
  --------------------------------------------------------- */
  function setupNavigation() {
    $$('.sidebar nav li').forEach((li) => {
      li.addEventListener('click', () => {
        const section = li.getAttribute('data-section');
        $$('.sidebar nav li').forEach((el) => el.classList.remove('active'));
        li.classList.add('active');

        $$('.section').forEach((sec) => sec.classList.remove('active'));
        const target = document.getElementById(`${section}-section`);
        if (target) target.classList.add('active');

        if (section === 'dashboard') loadDashboard();
      });
    });
  }

  /* ---------------------------------------------------------
     DASHBOARD
  --------------------------------------------------------- */
  async function loadDashboard() {
    try {
      const data = await apiRequest('GET', 'dashboard.php');
      $('#total-employees').textContent = data.total_employees;
      $('#active-employees').textContent = data.active_employees;
      $('#total-departments').textContent = data.total_departments;
      $('#total-positions').textContent = data.total_positions;
      $('#pending-leaves').textContent = data.pending_leaves;

      const list = $('#activity-list');
      if (!data.recent_activity || data.recent_activity.length === 0) {
        list.innerHTML = '<li class="empty-row">No recent activity yet.</li>';
      } else {
        list.innerHTML = data.recent_activity
          .map(
            (a) => `<li>
                <span>${escapeHtml(a.message)}</span>
                <span class="activity-time">${new Date(a.created_at).toLocaleString()}</span>
              </li>`
          )
          .join('');
      }
    } catch (err) {
      /* toast already shown by apiRequest */
    }
  }

  /* ---------------------------------------------------------
     SELECT DROPDOWN REFRESH
  --------------------------------------------------------- */
  function refreshAllSelects() {
    refreshDepartmentSelects();
    refreshPositionSelects();
    refreshEmployeeSelects();
    refreshManagerSelect();
  }

  function refreshDepartmentSelects() {
    ['#department', '#edit-department', '#position-department'].forEach((sel) => {
      const el = $(sel);
      if (!el) return;
      const current = el.value;
      el.innerHTML = '<option value="">Select Department</option>' +
        state.departments.map((d) => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
      if (current) el.value = current;
    });
  }

  function refreshPositionSelects() {
    ['#position', '#edit-position'].forEach((sel) => {
      const el = $(sel);
      if (!el) return;
      const current = el.value;
      el.innerHTML = '<option value="">Select Position</option>' +
        state.positions.map((p) => `<option value="${p.id}">${escapeHtml(p.title)}</option>`).join('');
      if (current) el.value = current;
    });
  }

  function refreshEmployeeSelects() {
    ['#salary-employee', '#leave-employee'].forEach((sel) => {
      const el = $(sel);
      if (!el) return;
      const current = el.value;
      el.innerHTML = '<option value="">Select Employee</option>' +
        state.employees.map((e) => `<option value="${e.id}">${escapeHtml(employeeFullName(e))}</option>`).join('');
      if (current) el.value = current;
    });
  }

  function refreshManagerSelect() {
    const el = $('#department-manager');
    if (!el) return;
    const current = el.value;
    el.innerHTML = '<option value="">Select Manager</option>' +
      state.employees.map((e) => `<option value="${e.id}">${escapeHtml(employeeFullName(e))}</option>`).join('');
    if (current) el.value = current;
  }

  /* ===========================================================
     EMPLOYEES
  =========================================================== */
  async function loadEmployees() {
    try {
      const page = state.pagination.employees.page;
      const perPage = state.pagination.employees.perPage;
      const params = new URLSearchParams({ page, per_page: perPage });
      if (state.searchTerm) params.set('search', state.searchTerm);

      const data = await apiRequest('GET', `employees.php?${params.toString()}`);
      state.employees = data.items;
      state.pagination.employees.totalPages = data.total_pages;
      state.pagination.employees.page = data.page;
      renderEmployees();
    } catch (err) {
      /* toast already shown */
    }
  }

  function setupEmployeeSection() {
    $('#add-employee-btn').addEventListener('click', () => {
      $('#add-employee-form').reset();
      refreshAllSelects();
      openModal('add-employee-modal');
    });

    $('#cancel-add-employee').addEventListener('click', () => closeModal('add-employee-modal'));
    $('#cancel-edit-employee').addEventListener('click', () => closeModal('edit-employee-modal'));

    $('#add-employee-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = {
        first_name: $('#first-name').value.trim(),
        last_name: $('#last-name').value.trim(),
        email: $('#email').value.trim(),
        phone: $('#phone').value.trim(),
        department_id: $('#department').value ? Number($('#department').value) : null,
        position_id: $('#position').value ? Number($('#position').value) : null,
        hire_date: $('#hire-date').value,
        salary: $('#salary').value ? Number($('#salary').value) : 0,
        status: $('#status').value
      };
      try {
        await apiRequest('POST', 'employees.php', payload);
        closeModal('add-employee-modal');
        showToast('success', 'Employee Added', `${payload.first_name} ${payload.last_name} was added successfully.`);
        await Promise.all([loadEmployees(), loadDepartments(), loadPositions()]);
        refreshAllSelects();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#edit-employee-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = Number($('#edit-employee-id').value);
      const payload = {
        first_name: $('#edit-first-name').value.trim(),
        last_name: $('#edit-last-name').value.trim(),
        email: $('#edit-email').value.trim(),
        phone: $('#edit-phone').value.trim(),
        department_id: $('#edit-department').value ? Number($('#edit-department').value) : null,
        position_id: $('#edit-position').value ? Number($('#edit-position').value) : null,
        hire_date: $('#edit-hire-date').value,
        salary: $('#edit-salary').value ? Number($('#edit-salary').value) : 0,
        status: $('#edit-status').value
      };
      try {
        await apiRequest('PUT', `employees.php?id=${id}`, payload);
        closeModal('edit-employee-modal');
        showToast('success', 'Employee Updated', `${payload.first_name} ${payload.last_name} was updated.`);
        await Promise.all([loadEmployees(), loadDepartments(), loadPositions()]);
        refreshAllSelects();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#delete-employee').addEventListener('click', () => {
      const id = Number($('#edit-employee-id').value);
      const name = `${$('#edit-first-name').value} ${$('#edit-last-name').value}`;
      showConfirmation(`Are you sure you want to delete ${name}?`, async () => {
        try {
          await apiRequest('DELETE', `employees.php?id=${id}`);
          closeModal('edit-employee-modal');
          showToast('success', 'Employee Deleted', `${name} was removed.`);
          await Promise.all([loadEmployees(), loadDepartments(), loadPositions()]);
          refreshAllSelects();
          loadDashboard();
        } catch (err) {
          /* toast already shown */
        }
      });
    });

    $('#search-btn').addEventListener('click', runSearch);
    $('#search-input').addEventListener('keyup', (e) => {
      if (e.key === 'Enter' || $('#search-input').value.trim() === '') runSearch();
    });
    function runSearch() {
      state.searchTerm = $('#search-input').value.trim();
      state.pagination.employees.page = 1;
      loadEmployees();
    }

    $('#export-employees').addEventListener('click', exportEmployeesCsv);

    $('#employees-prev-page').addEventListener('click', () => {
      if (state.pagination.employees.page > 1) {
        state.pagination.employees.page -= 1;
        loadEmployees();
      }
    });
    $('#employees-next-page').addEventListener('click', () => {
      if (state.pagination.employees.page < state.pagination.employees.totalPages) {
        state.pagination.employees.page += 1;
        loadEmployees();
      }
    });
  }

  function renderEmployees() {
    const tbody = $('#employees-table tbody');
    if (state.employees.length === 0) {
      tbody.innerHTML = `<tr><td colspan="8" class="empty-row">No employees found.</td></tr>`;
    } else {
      tbody.innerHTML = state.employees
        .map((e) => {
          const dept = findDepartment(e.department_id);
          const pos = findPosition(e.position_id);
          return `<tr>
              <td>${e.id}</td>
              <td>${escapeHtml(employeeFullName(e))}</td>
              <td>${dept ? escapeHtml(dept.name) : '-'}</td>
              <td>${pos ? escapeHtml(pos.title) : '-'}</td>
              <td>${escapeHtml(e.email)}</td>
              <td>${escapeHtml(e.phone)}</td>
              <td><span class="status-badge status-${e.status}">${formatStatusLabel(e.status)}</span></td>
              <td class="actions-cell">
                <button class="icon-btn edit-employee-row" data-id="${e.id}" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="icon-btn delete-employee-row" data-id="${e.id}" title="Delete"><i class="fas fa-trash"></i></button>
              </td>
            </tr>`;
        })
        .join('');

      $$('.edit-employee-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => openEditEmployee(Number(btn.dataset.id)))
      );
      $$('.delete-employee-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => {
          const id = Number(btn.dataset.id);
          const emp = findEmployee(id);
          showConfirmation(`Are you sure you want to delete ${employeeFullName(emp)}?`, async () => {
            try {
              await apiRequest('DELETE', `employees.php?id=${id}`);
              showToast('success', 'Employee Deleted', `${employeeFullName(emp)} was removed.`);
              await Promise.all([loadEmployees(), loadDepartments(), loadPositions()]);
              refreshAllSelects();
              loadDashboard();
            } catch (err) {
              /* toast already shown */
            }
          });
        })
      );
    }

    const { page, totalPages } = state.pagination.employees;
    $('#employees-page-info').textContent = `page ${page} of ${totalPages}`;
    $('#employees-prev-page').disabled = page <= 1;
    $('#employees-next-page').disabled = page >= totalPages;
  }

  function openEditEmployee(id) {
    const emp = findEmployee(id);
    if (!emp) return;
    refreshAllSelects();
    $('#edit-employee-id').value = emp.id;
    $('#edit-first-name').value = emp.first_name;
    $('#edit-last-name').value = emp.last_name;
    $('#edit-email').value = emp.email;
    $('#edit-phone').value = emp.phone;
    $('#edit-department').value = emp.department_id || '';
    $('#edit-position').value = emp.position_id || '';
    $('#edit-hire-date').value = emp.hire_date || '';
    $('#edit-salary').value = emp.salary || '';
    $('#edit-status').value = emp.status;
    openModal('edit-employee-modal');
  }

  async function exportEmployeesCsv() {
    let all;
    try {
      all = await apiRequest('GET', 'employees.php?page=1&per_page=1000');
    } catch (err) {
      return;
    }
    if (!all.items || all.items.length === 0) {
      showToast('error', 'Nothing to Export', 'There are no employees to export.');
      return;
    }
    const header = ['ID', 'Name', 'Department', 'Position', 'Email', 'Phone', 'Status', 'Hire Date', 'Salary'];
    const rows = all.items.map((e) => {
      const dept = findDepartment(e.department_id);
      const pos = findPosition(e.position_id);
      return [
        e.id, employeeFullName(e), dept ? dept.name : '', pos ? pos.title : '',
        e.email, e.phone, formatStatusLabel(e.status), e.hire_date || '', e.salary || 0
      ];
    });
    const csv = [header, ...rows]
      .map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))
      .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'employees.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    showToast('success', 'Export Complete', 'Employee list exported to CSV.');
  }

  /* ===========================================================
     DEPARTMENTS
  =========================================================== */
  async function loadDepartments() {
    try {
      state.departments = await apiRequest('GET', 'departments.php');
      renderDepartments();
    } catch (err) {
      /* toast already shown */
    }
  }

  function setupDepartmentSection() {
    $('#add-department-btn').addEventListener('click', () => {
      $('#department-form').reset();
      $('#edit-department-id').value = '';
      $('#department-modal-title').textContent = 'Add New Department';
      $('#delete-department').style.display = 'none';
      refreshManagerSelect();
      openModal('department-modal');
    });

    $('#cancel-department').addEventListener('click', () => closeModal('department-modal'));

    $('#department-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = $('#edit-department-id').value;
      const payload = {
        name: $('#department-name').value.trim(),
        manager_id: $('#department-manager').value ? Number($('#department-manager').value) : null,
        budget: Number($('#department-budget').value) || 0
      };
      try {
        if (id) {
          await apiRequest('PUT', `departments.php?id=${id}`, payload);
          showToast('success', 'Department Updated', `${payload.name} was updated.`);
        } else {
          await apiRequest('POST', 'departments.php', payload);
          showToast('success', 'Department Added', `${payload.name} was added.`);
        }
        closeModal('department-modal');
        await loadDepartments();
        refreshAllSelects();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#delete-department').addEventListener('click', () => {
      const id = Number($('#edit-department-id').value);
      const dept = findDepartment(id);
      showConfirmation(`Are you sure you want to delete ${dept ? dept.name : 'this department'}?`, async () => {
        try {
          await apiRequest('DELETE', `departments.php?id=${id}`);
          closeModal('department-modal');
          showToast('success', 'Department Deleted', `${dept ? dept.name : 'Department'} was removed.`);
          await loadDepartments();
          refreshAllSelects();
          loadDashboard();
        } catch (err) {
          /* toast already shown */
        }
      });
    });

    $('#departments-prev-page').addEventListener('click', () => {
      if (state.pagination.departments.page > 1) {
        state.pagination.departments.page -= 1;
        renderDepartments();
      }
    });
    $('#departments-next-page').addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(state.departments.length / state.pagination.departments.perPage));
      if (state.pagination.departments.page < totalPages) {
        state.pagination.departments.page += 1;
        renderDepartments();
      }
    });
  }

  function renderDepartments() {
    const tbody = $('#department-table tbody');
    const perPage = state.pagination.departments.perPage;
    const totalPages = Math.max(1, Math.ceil(state.departments.length / perPage));
    if (state.pagination.departments.page > totalPages) state.pagination.departments.page = totalPages;
    const page = state.pagination.departments.page;
    const start = (page - 1) * perPage;
    const pageItems = state.departments.slice(start, start + perPage);

    if (pageItems.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" class="empty-row">No departments found.</td></tr>`;
    } else {
      tbody.innerHTML = pageItems
        .map(
          (d) => `<tr>
              <td>${d.id}</td>
              <td>${escapeHtml(d.name)}</td>
              <td>${d.manager_name ? escapeHtml(d.manager_name) : '-'}</td>
              <td>${formatCurrency(d.budget)}</td>
              <td>${d.employee_count}</td>
              <td class="actions-cell">
                <button class="icon-btn edit-department-row" data-id="${d.id}" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="icon-btn delete-department-row" data-id="${d.id}" title="Delete"><i class="fas fa-trash"></i></button>
              </td>
            </tr>`
        )
        .join('');

      $$('.edit-department-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => openEditDepartment(Number(btn.dataset.id)))
      );
      $$('.delete-department-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => {
          const id = Number(btn.dataset.id);
          const dept = findDepartment(id);
          showConfirmation(`Are you sure you want to delete ${dept.name}?`, async () => {
            try {
              await apiRequest('DELETE', `departments.php?id=${id}`);
              showToast('success', 'Department Deleted', `${dept.name} was removed.`);
              await loadDepartments();
              refreshAllSelects();
              loadDashboard();
            } catch (err) {
              /* toast already shown */
            }
          });
        })
      );
    }

    $('#departments-page-info').textContent = `page ${page} of ${totalPages}`;
    $('#departments-prev-page').disabled = page <= 1;
    $('#departments-next-page').disabled = page >= totalPages;
  }

  function openEditDepartment(id) {
    const dept = findDepartment(id);
    if (!dept) return;
    refreshManagerSelect();
    $('#department-modal-title').textContent = 'Edit Department';
    $('#edit-department-id').value = dept.id;
    $('#department-name').value = dept.name;
    $('#department-manager').value = dept.manager_id || '';
    $('#department-budget').value = dept.budget;
    $('#delete-department').style.display = 'inline-block';
    openModal('department-modal');
  }

  /* ===========================================================
     POSITIONS
  =========================================================== */
  async function loadPositions() {
    try {
      state.positions = await apiRequest('GET', 'positions.php');
      renderPositions();
    } catch (err) {
      /* toast already shown */
    }
  }

  function setupPositionSection() {
    $('#add-positions-btn').addEventListener('click', () => {
      $('#position-form').reset();
      $('#position-id').value = '';
      $('#position-modal-title').textContent = 'Add New Position';
      $('#delete-position').style.display = 'none';
      refreshDepartmentSelects();
      openModal('position-modal');
    });

    $('#cancel-position').addEventListener('click', () => closeModal('position-modal'));

    $('#position-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = $('#position-id').value;
      const payload = {
        title: $('#position-title').value.trim(),
        department_id: $('#position-department').value ? Number($('#position-department').value) : null,
        base_salary: Number($('#salary-base').value) || 0,
        description: $('#position-description').value.trim()
      };
      try {
        if (id) {
          await apiRequest('PUT', `positions.php?id=${id}`, payload);
          showToast('success', 'Position Updated', `${payload.title} was updated.`);
        } else {
          await apiRequest('POST', 'positions.php', payload);
          showToast('success', 'Position Added', `${payload.title} was added.`);
        }
        closeModal('position-modal');
        await loadPositions();
        refreshAllSelects();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#delete-position').addEventListener('click', () => {
      const id = Number($('#position-id').value);
      const pos = findPosition(id);
      showConfirmation(`Are you sure you want to delete ${pos ? pos.title : 'this position'}?`, async () => {
        try {
          await apiRequest('DELETE', `positions.php?id=${id}`);
          closeModal('position-modal');
          showToast('success', 'Position Deleted', `${pos ? pos.title : 'Position'} was removed.`);
          await loadPositions();
          refreshAllSelects();
          loadDashboard();
        } catch (err) {
          /* toast already shown */
        }
      });
    });

    $('#positions-prev-page').addEventListener('click', () => {
      if (state.pagination.positions.page > 1) {
        state.pagination.positions.page -= 1;
        renderPositions();
      }
    });
    $('#positions-next-page').addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(state.positions.length / state.pagination.positions.perPage));
      if (state.pagination.positions.page < totalPages) {
        state.pagination.positions.page += 1;
        renderPositions();
      }
    });
  }

  function renderPositions() {
    const tbody = $('#position-table tbody');
    const perPage = state.pagination.positions.perPage;
    const totalPages = Math.max(1, Math.ceil(state.positions.length / perPage));
    if (state.pagination.positions.page > totalPages) state.pagination.positions.page = totalPages;
    const page = state.pagination.positions.page;
    const start = (page - 1) * perPage;
    const pageItems = state.positions.slice(start, start + perPage);

    if (pageItems.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" class="empty-row">No positions found.</td></tr>`;
    } else {
      tbody.innerHTML = pageItems
        .map(
          (p) => `<tr>
              <td>${p.id}</td>
              <td>${escapeHtml(p.title)}</td>
              <td>${p.department_name ? escapeHtml(p.department_name) : '-'}</td>
              <td>${formatCurrency(p.base_salary)}</td>
              <td>${p.employee_count}</td>
              <td class="actions-cell">
                <button class="icon-btn edit-position-row" data-id="${p.id}" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="icon-btn delete-position-row" data-id="${p.id}" title="Delete"><i class="fas fa-trash"></i></button>
              </td>
            </tr>`
        )
        .join('');

      $$('.edit-position-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => openEditPosition(Number(btn.dataset.id)))
      );
      $$('.delete-position-row', tbody).forEach((btn) =>
        btn.addEventListener('click', () => {
          const id = Number(btn.dataset.id);
          const pos = findPosition(id);
          showConfirmation(`Are you sure you want to delete ${pos.title}?`, async () => {
            try {
              await apiRequest('DELETE', `positions.php?id=${id}`);
              showToast('success', 'Position Deleted', `${pos.title} was removed.`);
              await loadPositions();
              refreshAllSelects();
              loadDashboard();
            } catch (err) {
              /* toast already shown */
            }
          });
        })
      );
    }

    $('#positions-page-info').textContent = `page ${page} of ${totalPages}`;
    $('#positions-prev-page').disabled = page <= 1;
    $('#positions-next-page').disabled = page >= totalPages;
  }

  function openEditPosition(id) {
    const pos = findPosition(id);
    if (!pos) return;
    refreshDepartmentSelects();
    $('#position-modal-title').textContent = 'Edit Position';
    $('#position-id').value = pos.id;
    $('#position-title').value = pos.title;
    $('#position-department').value = pos.department_id || '';
    $('#salary-base').value = pos.base_salary;
    $('#position-description').value = pos.description || '';
    $('#delete-position').style.display = 'inline-block';
    openModal('position-modal');
  }

  /* ===========================================================
     SALARIES
  =========================================================== */
  async function loadSalaries() {
    try {
      state.salaries = await apiRequest('GET', 'salaries.php');
      renderSalaries();
    } catch (err) {
      /* toast already shown */
    }
  }

  function setupSalarySection() {
    $('#add-salaries-btn').addEventListener('click', () => {
      $('#salary-form').reset();
      $('#salary-id').value = '';
      $('#salary-modal-title').textContent = 'Add New Salary';
      $('#delete-salary').style.display = 'none';
      refreshEmployeeSelects();
      openModal('salary-modal');
    });

    $('#cancel-salary').addEventListener('click', () => closeModal('salary-modal'));

    $('#salary-employee').addEventListener('change', () => {
      const emp = findEmployee(Number($('#salary-employee').value));
      if (emp) {
        $('#salary-basic').value = emp.salary || 0;
        const pos = findPosition(emp.position_id);
        $('#position-salary').value = pos ? pos.base_salary : 0;
      }
    });

    $('#salary-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = $('#salary-id').value;
      const payload = {
        employee_id: $('#salary-employee').value ? Number($('#salary-employee').value) : null,
        basic_salary: Number($('#salary-basic').value) || 0,
        position_salary: Number($('#position-salary').value) || 0,
        allowances: Number($('#salary-allowances').value) || 0,
        deductions: Number($('#salary-deductions').value) || 0,
        pay_month: $('#salary-month').value ? `${$('#salary-month').value}-01` : '',
        status: $('#salary-status').value,
        notes: $('#salary-notes').value.trim()
      };
      try {
        if (id) {
          await apiRequest('PUT', `salaries.php?id=${id}`, payload);
          showToast('success', 'Salary Updated', 'Salary record was updated.');
        } else {
          await apiRequest('POST', 'salaries.php', payload);
          showToast('success', 'Salary Added', 'Salary record was added.');
        }
        closeModal('salary-modal');
        await loadSalaries();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#delete-salary').addEventListener('click', () => {
      const id = Number($('#salary-id').value);
      showConfirmation('Are you sure you want to delete this salary record?', async () => {
        try {
          await apiRequest('DELETE', `salaries.php?id=${id}`);
          closeModal('salary-modal');
          showToast('success', 'Salary Deleted', 'Salary record was removed.');
          await loadSalaries();
          loadDashboard();
        } catch (err) {
          /* toast already shown */
        }
      });
    });
  }

  function renderSalaries() {
    const tbody = $('#salaries-table tbody');
    if (state.salaries.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9" class="empty-row">No salary records found.</td></tr>`;
      return;
    }
    tbody.innerHTML = state.salaries
      .map(
        (s) => `<tr>
            <td>${s.id}</td>
            <td>${s.employee_name ? escapeHtml(s.employee_name) : '-'}</td>
            <td>${s.position_title ? escapeHtml(s.position_title) : '-'}</td>
            <td>${formatCurrency(s.basic_salary)}</td>
            <td>${formatCurrency(s.allowances)}</td>
            <td>${formatCurrency(s.deductions)}</td>
            <td>${formatCurrency(s.net_salary)}</td>
            <td>${s.pay_month ? s.pay_month.slice(0, 7) : '-'}</td>
            <td class="actions-cell">
              <button class="icon-btn edit-salary-row" data-id="${s.id}" title="Edit"><i class="fas fa-edit"></i></button>
              <button class="icon-btn delete-salary-row" data-id="${s.id}" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>`
      )
      .join('');

    $$('.edit-salary-row', tbody).forEach((btn) =>
      btn.addEventListener('click', () => openEditSalary(Number(btn.dataset.id)))
    );
    $$('.delete-salary-row', tbody).forEach((btn) =>
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.id);
        showConfirmation('Are you sure you want to delete this salary record?', async () => {
          try {
            await apiRequest('DELETE', `salaries.php?id=${id}`);
            showToast('success', 'Salary Deleted', 'Salary record was removed.');
            await loadSalaries();
            loadDashboard();
          } catch (err) {
            /* toast already shown */
          }
        });
      })
    );
  }

  function openEditSalary(id) {
    const sal = state.salaries.find((s) => s.id === id);
    if (!sal) return;
    refreshEmployeeSelects();
    $('#salary-modal-title').textContent = 'Edit Salary';
    $('#salary-id').value = sal.id;
    $('#salary-employee').value = sal.employee_id || '';
    $('#salary-basic').value = sal.basic_salary;
    $('#position-salary').value = sal.position_salary;
    $('#salary-allowances').value = sal.allowances;
    $('#salary-deductions').value = sal.deductions;
    $('#salary-month').value = sal.pay_month ? sal.pay_month.slice(0, 7) : '';
    $('#salary-status').value = sal.status;
    $('#salary-notes').value = sal.notes || '';
    $('#delete-salary').style.display = 'inline-block';
    openModal('salary-modal');
  }

  /* ===========================================================
     LEAVES
  =========================================================== */
  async function loadLeaves() {
    try {
      state.leaves = await apiRequest('GET', 'leave_requests.php');
      renderLeaves();
    } catch (err) {
      /* toast already shown */
    }
  }

  function setupLeaveSection() {
    $('#add-leaves-btn').addEventListener('click', () => {
      $('#leave-form').reset();
      $('#leave-id').value = '';
      $('#leave-modal-title').textContent = 'Add New Leave';
      $('#delete-leave').style.display = 'none';
      refreshEmployeeSelects();
      openModal('leave-modal');
    });

    $('#cancel-leave').addEventListener('click', () => closeModal('leave-modal'));

    $('#leave-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = $('#leave-id').value;
      const payload = {
        employee_id: $('#leave-employee').value ? Number($('#leave-employee').value) : null,
        leave_type: $('#leave-type').value,
        start_date: $('#leave-start').value,
        end_date: $('#leave-end').value,
        status: $('#leave-status').value,
        reason: $('#leave-reason').value.trim()
      };
      try {
        if (id) {
          await apiRequest('PUT', `leave_requests.php?id=${id}`, payload);
          showToast('success', 'Leave Updated', 'Leave record was updated.');
        } else {
          await apiRequest('POST', 'leave_requests.php', payload);
          showToast('success', 'Leave Added', 'Leave record was added.');
        }
        closeModal('leave-modal');
        await loadLeaves();
        loadDashboard();
      } catch (err) {
        /* toast already shown */
      }
    });

    $('#delete-leave').addEventListener('click', () => {
      const id = Number($('#leave-id').value);
      showConfirmation('Are you sure you want to delete this leave record?', async () => {
        try {
          await apiRequest('DELETE', `leave_requests.php?id=${id}`);
          closeModal('leave-modal');
          showToast('success', 'Leave Deleted', 'Leave record was removed.');
          await loadLeaves();
          loadDashboard();
        } catch (err) {
          /* toast already shown */
        }
      });
    });
  }

  function renderLeaves() {
    const tbody = $('#leaves-table tbody');
    if (state.leaves.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9" class="empty-row">No leave records found.</td></tr>`;
      return;
    }
    tbody.innerHTML = state.leaves
      .map(
        (l) => `<tr>
            <td>${l.id}</td>
            <td>${l.employee_name ? escapeHtml(l.employee_name) : '-'}</td>
            <td>${escapeHtml(capitalize(l.leave_type))}</td>
            <td>${formatDate(l.start_date)}</td>
            <td>${formatDate(l.end_date)}</td>
            <td>${l.days}</td>
            <td>${escapeHtml(l.reason)}</td>
            <td><span class="status-badge status-${l.status}">${capitalize(l.status)}</span></td>
            <td class="actions-cell">
              <button class="icon-btn edit-leave-row" data-id="${l.id}" title="Edit"><i class="fas fa-edit"></i></button>
              <button class="icon-btn delete-leave-row" data-id="${l.id}" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>`
      )
      .join('');

    $$('.edit-leave-row', tbody).forEach((btn) =>
      btn.addEventListener('click', () => openEditLeave(Number(btn.dataset.id)))
    );
    $$('.delete-leave-row', tbody).forEach((btn) =>
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.id);
        showConfirmation('Are you sure you want to delete this leave record?', async () => {
          try {
            await apiRequest('DELETE', `leave_requests.php?id=${id}`);
            showToast('success', 'Leave Deleted', 'Leave record was removed.');
            await loadLeaves();
            loadDashboard();
          } catch (err) {
            /* toast already shown */
          }
        });
      })
    );
  }

  function openEditLeave(id) {
    const lv = state.leaves.find((l) => l.id === id);
    if (!lv) return;
    refreshEmployeeSelects();
    $('#leave-modal-title').textContent = 'Edit Leave';
    $('#leave-id').value = lv.id;
    $('#leave-employee').value = lv.employee_id || '';
    $('#leave-start').value = lv.start_date || '';
    $('#leave-end').value = lv.end_date || '';
    $('#leave-type').value = lv.leave_type;
    $('#leave-status').value = lv.status;
    $('#leave-reason').value = lv.reason || '';
    $('#delete-leave').style.display = 'inline-block';
    openModal('leave-modal');
  }

  /* ===========================================================
     REPORTS (Chart.js — driven entirely by the loaded data)
  =========================================================== */
  function setupReportsSection() {
    $('#generate-report').addEventListener('click', generateReport);
  }

  function generateReport() {
    const type = $('#reports-type').value;
    const canvas = $('#reports-chart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (state.reportChart) {
      state.reportChart.destroy();
      state.reportChart = null;
    }

    let config;
    switch (type) {
      case 'department-distribution': config = buildDepartmentDistributionConfig(); break;
      case 'salary-distribution': config = buildSalaryDistributionConfig(); break;
      case 'hiring-trends': config = buildHiringTrendsConfig(); break;
      case 'attrition-rate': config = buildAttritionRateConfig(); break;
      case 'leave-analysis': config = buildLeaveAnalysisConfig(); break;
      default: config = buildDepartmentDistributionConfig();
    }

    if (!config) {
      showToast('error', 'No Data', 'There is not enough data yet to generate this report.');
      return;
    }
    state.reportChart = new Chart(ctx, config);
  }

  function buildDepartmentDistributionConfig() {
    if (state.departments.length === 0) return null;
    return {
      type: 'pie',
      data: {
        labels: state.departments.map((d) => d.name),
        datasets: [{ label: 'Employees per Department', data: state.departments.map((d) => d.employee_count) }]
      },
      options: { responsive: true, plugins: { title: { display: true, text: 'Department Distribution' } } }
    };
  }

  function buildSalaryDistributionConfig() {
    if (state.employees.length === 0) return null;
    const buckets = [
      { label: '< 30k', min: 0, max: 30000 },
      { label: '30k - 60k', min: 30000, max: 60000 },
      { label: '60k - 90k', min: 60000, max: 90000 },
      { label: '90k - 120k', min: 90000, max: 120000 },
      { label: '120k+', min: 120000, max: Infinity }
    ];
    const counts = buckets.map(
      (b) => state.employees.filter((e) => (Number(e.salary) || 0) >= b.min && (Number(e.salary) || 0) < b.max).length
    );
    return {
      type: 'bar',
      data: { labels: buckets.map((b) => b.label), datasets: [{ label: 'Number of Employees', data: counts }] },
      options: {
        responsive: true,
        plugins: { title: { display: true, text: 'Salary Distribution' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    };
  }

  function buildHiringTrendsConfig() {
    const withDates = state.employees.filter((e) => e.hire_date);
    if (withDates.length === 0) return null;
    const counts = {};
    withDates.forEach((e) => {
      const d = new Date(e.hire_date);
      if (isNaN(d.getTime())) return;
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      counts[key] = (counts[key] || 0) + 1;
    });
    const labels = Object.keys(counts).sort();
    return {
      type: 'line',
      data: { labels, datasets: [{ label: 'New Hires', data: labels.map((k) => counts[k]), fill: false, tension: 0.2 }] },
      options: {
        responsive: true,
        plugins: { title: { display: true, text: 'Hiring Trends' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    };
  }

  function buildAttritionRateConfig() {
    if (state.employees.length === 0) return null;
    const active = state.employees.filter((e) => e.status === 'active').length;
    const onLeave = state.employees.filter((e) => e.status === 'on-leave').length;
    const terminated = state.employees.filter((e) => e.status === 'terminated').length;
    return {
      type: 'doughnut',
      data: { labels: ['Active', 'On Leave', 'Terminated'], datasets: [{ label: 'Employee Status', data: [active, onLeave, terminated] }] },
      options: { responsive: true, plugins: { title: { display: true, text: 'Attrition / Status Breakdown' } } }
    };
  }

  function buildLeaveAnalysisConfig() {
    if (state.leaves.length === 0) return null;
    const types = ['sick', 'vacation', 'personal', 'maternity', 'paternity', 'others'];
    const data = types.map((t) => state.leaves.filter((l) => l.leave_type === t).length);
    return {
      type: 'bar',
      data: { labels: types.map(capitalize), datasets: [{ label: 'Leave Requests by Type', data }] },
      options: {
        responsive: true,
        plugins: { title: { display: true, text: 'Leave Analysis' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    };
  }

  /* ===========================================================
     SETTINGS (client-side only — wire to a settings API if needed)
  =========================================================== */
  function setupSettingsSection() {
    $$('.tab-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tab = btn.getAttribute('data-tab');
        $$('.tab-btn').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        $$('.tab-content').forEach((tc) => tc.classList.remove('active'));
        const target = $(`.tab-content[data-tab="${tab}"]`);
        if (target) target.classList.add('active');
      });
    });

    $('#general-settings').addEventListener('submit', (e) => {
      e.preventDefault();
      showToast('success', 'Settings Saved', 'General settings were saved.');
    });

    $('#notification-settings').addEventListener('submit', (e) => {
      e.preventDefault();
      showToast('success', 'Settings Saved', 'Notification settings were saved.');
    });

    $('#security-settings').addEventListener('submit', (e) => {
      e.preventDefault();
      const pass = $('#password').value;
      const confirm = $('#confirm-password').value;
      if (pass || confirm) {
        if (pass !== confirm) {
          showToast('error', 'Password Mismatch', 'New password and confirmation do not match.');
          return;
        }
        if (pass.length < 6) {
          showToast('error', 'Weak Password', 'Password must be at least 6 characters long.');
          return;
        }
      }
      $('#security-settings').reset();
      showToast('success', 'Settings Saved', 'Security settings were saved.');
    });
  }

  /* ===========================================================
     MODAL HELPERS
  =========================================================== */
  function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('active');
  }
  function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
  }
  function setupModalGenerics() {
    $$('.modal .close-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const modal = btn.closest('.modal');
        if (modal) modal.classList.remove('active');
      });
    });
    $$('.modal').forEach((modal) => {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
      });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') $$('.modal.active').forEach((modal) => modal.classList.remove('active'));
    });
  }

  /* ===========================================================
     CONFIRMATION MODAL
  =========================================================== */
  function setupConfirmationModal() {
    $('#confirm-action').addEventListener('click', () => {
      const cb = state.confirmCallback;
      state.confirmCallback = null;
      closeModal('confirmation-modal');
      if (typeof cb === 'function') cb();
    });
    $('#cancel-confirmation').addEventListener('click', () => {
      state.confirmCallback = null;
      closeModal('confirmation-modal');
    });
  }
  function showConfirmation(message, onConfirm) {
    $('#confirmation-message').textContent = message;
    state.confirmCallback = onConfirm;
    openModal('confirmation-modal');
  }

  /* ===========================================================
     TOAST NOTIFICATIONS
  =========================================================== */
  let toastTimer = null;
  function setupToast() {
    $('.close-toast').addEventListener('click', hideToast);
  }
  function showToast(type, title, message) {
    const toast = $('#notification-toast');
    const icon = $('#toast-icon');
    $('#toast-title').textContent = title;
    $('#toast-message').textContent = message;
    const iconMap = {
      success: 'fa-circle-check',
      error: 'fa-circle-xmark',
      warning: 'fa-triangle-exclamation',
      info: 'fa-circle-info'
    };
    icon.className = 'fas ' + (iconMap[type] || iconMap.info);
    toast.className = 'toast'; // reset to base, then apply this type
    toast.classList.add(type === 'success' || type === 'error' || type === 'warning' ? type : 'info');
    toast.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(hideToast, 4000);
  }
  function hideToast() {
    const toast = $('#notification-toast');
    toast.classList.remove('show');
    if (toastTimer) {
      clearTimeout(toastTimer);
      toastTimer = null;
    }
  }
})();
