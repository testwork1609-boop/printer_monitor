// Toggle sidebar
document.addEventListener('DOMContentLoaded', function() {
    const navToggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (navToggle) {
        navToggle.addEventListener('click', function() {
            sidebar.classList.toggle('sidebar-closed');
        });
    }
    
    // Auto-hide messages after 5 seconds
    const messages = document.querySelectorAll('.message');
    messages.forEach(msg => {
        setTimeout(() => {
            msg.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => msg.remove(), 300);
        }, 5000);
    });
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#printerTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }
});

// Open add modal
function openAddModal() {
    const modal = document.getElementById('printerModal');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('printerForm');
    const editIndex = document.getElementById('editIndex');
    
    if (modal && modalTitle && form) {
        modalTitle.textContent = 'Dodaj drukarkę';
        form.reset();
        editIndex.value = '0';
        modal.classList.add('active');
    }
}

// Close modal
function closeModal() {
    const modal = document.getElementById('printerModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// Edit printer
function editPrinter(id) {
    if (typeof printersData === 'undefined') {
        console.error('printersData is not defined');
        return;
    }
    
    const printer = printersData.find(p => p.id == id);
    if (!printer) {
        alert('Nie znaleziono drukarki');
        return;
    }
    
    const modal = document.getElementById('printerModal');
    const modalTitle = document.getElementById('modalTitle');
    
    if (modal && modalTitle) {
        modalTitle.textContent = 'Edytuj drukarkę';
        
        document.getElementById('printerName').value = printer.name || '';
        document.getElementById('printerIP').value = printer.ip || '';
        document.getElementById('host_number').value = printer.host_number || '';
        document.getElementById('location').value = printer.location || '';
        document.getElementById('hall').value = printer.hall || 'K3';
        document.getElementById('editIndex').value = id;
        
        modal.classList.add('active');
    }
}

// Confirm delete
function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć tę drukarkę?')) {
        window.location.href = '?action=delete&id=' + id;
    }
}

// Scan network
function scanNetwork() {
    if (confirm('Rozpocząć skanowanie sieci w poszukiwaniu drukarek? Może to potrwać kilka minut.')) {
        window.location.href = '?action=scan';
    }
}

// Close modal on outside click
window.addEventListener('click', function(event) {
    const modal = document.getElementById('printerModal');
    if (event.target === modal) {
        closeModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
    }
});

// Form validation
const printerForm = document.getElementById('printerForm');
if (printerForm) {
    printerForm.addEventListener('submit', function(e) {
        const name = document.getElementById('printerName').value.trim();
        const ip = document.getElementById('printerIP').value.trim();
        
        if (!name || !ip) {
            alert('Nazwa i adres IP są wymagane');
            e.preventDefault();
            return false;
        }
        
        // Validate IP address format
        const ipPattern = /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/;
        if (!ipPattern.test(ip)) {
            alert('Nieprawidłowy format adresu IP');
            e.preventDefault();
            return false;
        }
        
        // Check for duplicates (excluding current edited printer)
        const editIndex = document.getElementById('editIndex').value;
        if (typeof printersData !== 'undefined') {
            const duplicate = printersData.find(p => {
                return p.id != editIndex && (p.name === name || p.ip === ip);
            });
            
            if (duplicate) {
                alert('Drukarka o tej nazwie lub adresie IP już istnieje');
                e.preventDefault();
                return false;
            }
        }
        
        return true;
    });
}

// Animation for slide out
const style = document.createElement('style');
style.textContent = `
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);