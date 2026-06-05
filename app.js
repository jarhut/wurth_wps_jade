import { createApp, ref, reactive, watch, onMounted } from 'vue';
import Compressor from 'compressorjs';

createApp({
    setup() {
        const rates = ref({});
        const saveStatus = ref('idle');
        const isSubmitted = ref(false);
        const isSubmitting = ref(false);      
        const showDraftModal = ref(false);    
        const filesToUpload = ref([]);
        const currentUser = ref(null);        
        const fileInputRef = ref(null);       
        const currentTab = ref('claims');
        const isFinanceLoggedIn = ref(false);
        const financeClaims = ref([]);
        const myClaims = ref([]);
        
        const loginForm = reactive({ email: '', password: '' });
        const authError = ref(null);
        let debounceTimer = null;

        const categories = ['A: Travel Expenses', 'B: Offices Supplies', 'C: Meals & Entertainment', 'D: Telecommunication', 'E: Marketing', 'F: Logistics'];
        const pillars = ["Manufacturing AD", "Manufacturing DXB & NE", "Construction", "Infrastructure", "Operations", "WPS"];
        
        const claim = reactive({ id: null, event_name: '', total_aed: 0, items: [] });

        const accountingMatrix = {
            "A: Travel Expenses": [
                { name: "Rental cars, car costs", code: "4412212" },
                { name: "Flight costs", code: "4412213" },
                { name: "Daily Travel expenses", code: "4412216" },
                { name: "Hotels", code: "4412217" }
            ],
            "E: Marketing": [
                { name: "MKT - EXHIBITIONS", code: "4411918" },
                { name: "MKT - BRAND AWARENESS, PUBLIC RELATIONS", code: "4411921" },
                { name: "MKT - Sales Promotion, samples free-of-charge", code: "4411911" },
                { name: "MKT - Sales Customer Events", code: "4411913" }
            ]
        };

        onMounted(async () => {
            const isSessionActive = await fetchUser();
            if (isSessionActive) {
                await initializeWorkspace();
            }
        });

        const initializeWorkspace = async () => {
            try {
                const res = await fetch('api.php?action=rates');
                const data = await res.json();
                rates.value = data.rates;
                
                if (currentUser.value.role === 'finance') {
                    currentTab.value = 'finance';
                    isFinanceLoggedIn.value = true;
                    await fetchFinanceClaims();
                } else {
                    currentTab.value = 'claims';
                    await checkActiveDraft();
                    await fetchMyClaims();
                }
            } catch (e) { console.error("Initialization loop error:", e); }
        };

        const handleLogin = async () => {
            authError.value = null;
            isSubmitting.value = true;
            try {
                const res = await fetch('api.php?action=login', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(loginForm)
                });
                const data = await res.json();
                
                if (res.ok && data.status === 'success') {
                    await fetchUser();
                    await initializeWorkspace();
                    loginForm.email = ''; loginForm.password = '';
                } else { authError.value = data.error || 'Authentication declined.'; }
            } catch (e) { authError.value = 'Network gateway route access broken.'; }
            finally { isSubmitting.value = false; }
        };

        const handleLogout = async () => {
            await fetch('api.php?action=logout');
            currentUser.value = null; isFinanceLoggedIn.value = false;
            myClaims.value = [];
            resetForm();
        };

        const fetchUser = async () => {
            try {
                const res = await fetch('api.php?action=me');
                const data = await res.json();
                if (data.status === 'success' && data.user) {
                    currentUser.value = data.user;
                    return true;
                }
            } catch (e) { console.error(e); }
            currentUser.value = null; return false;
        };

        const isDraftBlank = (d) => {
            if (!d) return true;
            if (d.event_name && d.event_name.trim() !== '') return false;
            if (!d.items || d.items.length === 0) return true;
            if (d.items.length > 1) return false;
            const item = d.items[0];
            const categoryEmpty = !item.category || item.category === '';
            const costTypeEmpty = !item.cost_type_name || item.cost_type_name === '';
            const descEmpty = !item.description || item.description.trim() === '';
            const amountEmpty = !item.original_amount || parseFloat(item.original_amount) === 0;
            return categoryEmpty && costTypeEmpty && descEmpty && amountEmpty;
        };

        const checkActiveDraft = async () => {
            try {
                const res = await fetch('api.php?action=get_latest_draft');
                const data = await res.json();
                if (data.status === 'success' && data.claim) {
                    if (isDraftBlank(data.claim)) {
                        const d = data.claim;
                        claim.id = d.id;
                        claim.event_name = d.event_name;
                        claim.total_aed = d.total_aed;
                        claim.items = d.items || [];
                        if (claim.items.length === 0) addItem();
                        showDraftModal.value = false;
                    } else {
                        claim._foundDraft = data.claim;
                        showDraftModal.value = true;
                    }
                } else { if (claim.items.length === 0) addItem(); }
            } catch (e) { if (claim.items.length === 0) addItem(); }
        };

        const keepDraft = () => {
            const d = claim._foundDraft;
            claim.id = d.id; claim.event_name = d.event_name; claim.total_aed = d.total_aed; claim.items = d.items || [];
            showDraftModal.value = false;
        };

        const discardAndNew = async () => {
            if (claim._foundDraft) {
                await fetch(`api.php?action=discard_draft&id=${claim._foundDraft.id}`, { method: 'DELETE' });
            }
            showDraftModal.value = false; resetForm();
        };

        const addItem = () => {
            claim.items.push({ category: '', cost_type_name: '', cost_type_nr: '', pillar_name: '', expense_date: new Date().toISOString().split('T')[0], description: '', country: 'UAE', receipt_no: '', original_amount: 0, original_currency: 'AED', exchange_rate: 1.0, aed_amount: 0 });
        };

        const removeItem = (index) => { claim.items.splice(index, 1); };
        const handleCategoryChange = (item) => { item.cost_type_name = ''; item.cost_type_nr = ''; };
        const handleCostNameChange = (item) => {
            if (item.category && accountingMatrix[item.category]) {
                const selected = accountingMatrix[item.category].find(x => x.name === item.cost_type_name);
                if (selected) item.cost_type_nr = selected.code;
            }
        };

        watch(() => claim, (newClaim) => {
            if (!currentUser.value || currentUser.value.role !== 'claimant') return;
            let grandTotal = 0;
            newClaim.items.forEach(item => {
                const amt = parseFloat(item.original_amount) || 0;
                const rate = rates.value[item.original_currency] || 1;
                item.exchange_rate = rate;
                const converted = item.original_currency === 'AED' ? amt : (amt / rate);
                item.aed_amount = parseFloat(converted.toFixed(2));
                grandTotal += item.aed_amount;
            });
            claim.total_aed = parseFloat(grandTotal.toFixed(2));
            triggerAutosave();
        }, { deep: true });

        const triggerAutosave = () => {
            if (isSubmitted.value || showDraftModal.value || !claim.items.length) return;
            if (isDraftBlank(claim)) return;
            clearTimeout(debounceTimer); saveStatus.value = 'saving';
            debounceTimer = setTimeout(async () => {
                try {
                    const res = await fetch('api.php?action=autosave', {
                        method: 'PUT', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(claim)
                    });
                    const data = await res.json();
                    if (data.id && !claim.id) claim.id = data.id;
                    saveStatus.value = 'saved';
                    setTimeout(() => saveStatus.value = 'idle', 2000);
                } catch (e) { saveStatus.value = 'error'; }
            }, 1500);
        };

        const submitClaim = async () => {
            if (!claim.id) return alert("Please wait for draft to sync.");
            clearTimeout(debounceTimer); isSubmitting.value = true;
            const formData = new FormData();
            formData.append('claim_id', claim.id);
            filesToUpload.value.forEach(f => formData.append('receipts[]', f.file));

            try {
                const res = await fetch('api.php?action=submit', { method: 'POST', body: formData });
                if (res.ok) {
                    isSubmitted.value = true;
                    await fetchMyClaims();
                }
            } catch (e) { alert("Submission failed."); }
            isSubmitting.value = false;
        };

        const handleFiles = (e) => {
            const selectedFiles = Array.from(e.target.files);
            if (!selectedFiles.length) return;
            isSubmitting.value = true; let processedCount = 0;

            selectedFiles.forEach(file => {
                if (file.type === 'application/pdf') {
                    filesToUpload.value.push({ file: file, name: file.name, isImage: false, preview: null });
                    processedCount++; if (processedCount === selectedFiles.length) isSubmitting.value = false;
                } else if (file.type.startsWith('image/')) {
                    new Compressor(file, {
                        quality: 0.8, maxWidth: 1600, mimeType: 'image/jpeg',
                        success(result) {
                            filesToUpload.value.push({ file: result, name: result.name || file.name, isImage: true, preview: URL.createObjectURL(result) });
                            processedCount++; if (processedCount === selectedFiles.length) isSubmitting.value = false;
                        },
                        error() { processedCount++; if (processedCount === selectedFiles.length) isSubmitting.value = false; }
                    });
                }
            });
            if (fileInputRef.value) fileInputRef.value.value = '';
        };

        const removeFile = (index) => {
            const f = filesToUpload.value[index]; if (f.preview) URL.revokeObjectURL(f.preview);
            filesToUpload.value.splice(index, 1);
        };

        const fetchFinanceClaims = async () => {
            try {
                const res = await fetch('api.php?action=finance_claims');
                const data = await res.json();
                if (data.status === 'success') {
                    financeClaims.value = data.claims.map(c => ({
                        ...c,
                        _localStatus: c.status,
                        _localComments: c.finance_comments || ''
                    }));
                }
            } catch (e) { console.error("Finance fetch error:", e); }
        };

        const fetchMyClaims = async () => {
            try {
                const res = await fetch('api.php?action=my_claims');
                const data = await res.json();
                if (data.status === 'success') myClaims.value = data.claims;
            } catch (e) { console.error("My claims fetch error:", e); }
        };

        const processApproval = async (claimId, decisionStatus, operationalComments) => {
            isSubmitting.value = true;
            try {
                const res = await fetch('api.php?action=review_claim', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ claim_id: claimId, status: decisionStatus, comments: operationalComments || '' })
                });
                if (res.ok) await fetchFinanceClaims();
            } catch (e) { console.error(e); }
            finally { isSubmitting.value = false; }
        };

        const resetForm = () => {
            claim.id = null; claim.event_name = ''; claim.total_aed = 0; claim.items = []; filesToUpload.value = []; isSubmitted.value = false; addItem();
        };

        return {
            claim, categories, saveStatus, isSubmitted, isSubmitting, showDraftModal, currentUser, accountingMatrix, pillars, filesToUpload, fileInputRef, currentTab, isFinanceLoggedIn, financeClaims, myClaims, loginForm, authError,
            handleCategoryChange, handleCostNameChange, addItem, removeItem, handleFiles, removeFile, submitClaim, resetForm, keepDraft, discardAndNew, handleLogin, handleLogout, processApproval, fetchMyClaims
        };
    }
}).mount('#app');