// HTMLエスケープ用のヘルパー関数
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// 決済方法の読み込み
async function loadPaymentMethods() {
    try {
        const response = await fetch('api/payment_methods.php');
        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            throw new Error(`APIがJSONを返しませんでした: ${contentType}`);
        }

        const result = await response.json();
        const tbody = document.getElementById('paymentMethodsList');
        
        if (!tbody) {
            console.error('決済方法リストの要素が見つかりません');
            return;
        }
        
        tbody.innerHTML = '';
        
        if (result.success && result.data) {
            result.data.forEach(method => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtml(method.name)}</td>
                    <td>
                        <button onclick="deletePaymentMethod(${method.id})" class="btn btn-sm btn-danger">
                            <i class="bi bi-trash"></i> 削除
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            console.error('決済方法の読み込みに失敗:', result.message);
            tbody.innerHTML = `<tr><td colspan="2" class="text-danger">${escapeHtml(result.message || '決済方法を読み込めませんでした')}</td></tr>`;
        }
    } catch (error) {
        console.error('決済方法の読み込みエラー:', error);
        const tbody = document.getElementById('paymentMethodsList');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="2" class="text-danger">${escapeHtml(error.message)}</td></tr>`;
        }
    }
}

// 決済方法の追加
function addPaymentMethod() {
    const modal = new bootstrap.Modal(document.getElementById('addPaymentMethodModal'));
    modal.show();
}

// 決済方法の追加フォーム送信
async function submitPaymentMethod(form) {
    try {
        const formData = new FormData(form);
        const data = {
            name: formData.get('name'),
            type: formData.get('type')
        };

        if (!data.name || !data.type) {
            alert('決済方法名と種類を入力してください');
            return;
        }

        const response = await fetch('api/payment_methods.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            throw new Error(`APIがJSONを返しませんでした: ${contentType}`);
        }

        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || 'エラーが発生しました');
        }
        
        if (result.success) {
            await loadPaymentMethods();
            const modalElement = document.getElementById('addPaymentMethodModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            modal.hide();
            form.reset();
            alert('決済方法を追加しました');
        } else {
            throw new Error(result.message || '決済方法の追加に失敗しました');
        }
    } catch (error) {
        console.error('決済方法の追加エラー:', error);
        alert(error.message || '決済方法の追加に失敗しました');
    }
}

// 決済方法の削除
async function deletePaymentMethod(id) {
    if (!confirm('この決済方法を削除してもよろしいですか？\n※この決済方法を使用している取引がある場合は削除できません。')) {
        return;
    }
    
    try {
        const response = await fetch(`api/payment_methods.php?id=${id}`, {
            method: 'DELETE'
        });
        
        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            throw new Error(`APIがJSONを返しませんでした: ${contentType}`);
        }

        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || 'エラーが発生しました');
        }
        
        if (result.success) {
            await loadPaymentMethods();
            alert('決済方法を削除しました');
        } else {
            throw new Error(result.message || '決済方法の削除に失敗しました');
        }
    } catch (error) {
        console.error('決済方法の削除エラー:', error);
        alert(error.message || '決済方法の削除に失敗しました');
    }
}

// DOMContentLoadedイベントの処理
document.addEventListener('DOMContentLoaded', function() {
    // モーダルが表示されたときに決済方法を読み込む
    const paymentMethodModal = document.getElementById('paymentMethodModal');
    if (paymentMethodModal) {
        paymentMethodModal.addEventListener('shown.bs.modal', loadPaymentMethods);
    }
});
