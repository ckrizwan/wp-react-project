import React, {useState} from 'react';

// --- Style Objects (CSS-in-JS) ---
// We define styles here as JS objects to avoid build steps like Tailwind.
const styles = {
    container: {
        backgroundColor: '#0f172a', // slate-900
        minHeight: '100vh',
        fontFamily: 'sans-serif',
        color: '#cbd5e1', // slate-300
        padding: '32px',
    },
    header: {
        marginBottom: '32px',
    },
    h1: {
        fontSize: '2.25rem', // text-4xl
        fontWeight: 'bold',
        color: '#ffffff',
        display: 'flex',
        alignItems: 'center',
        gap: '12px',
    },
    p: {
        marginTop: '8px',
        color: '#64748b', // slate-400
    },
    apiKeySection: {
        marginBottom: '24px',
        backgroundColor: 'rgba(30, 41, 59, 0.5)', // slate-800/50
        padding: '16px',
        borderRadius: '8px',
        border: '1px solid #334155', // slate-700
    },
    input: {
        width: '100%',
        backgroundColor: '#1e293b', // slate-800
        border: '1px solid #475569', // slate-600
        borderRadius: '6px',
        padding: '8px 12px',
        color: '#ffffff',
        transition: 'all 0.2s',
    },
    textarea: {
        width: '100%',
        backgroundColor: '#1e293b', // slate-800
        border: '1px solid #475569', // slate-600
        borderRadius: '6px',
        padding: '8px 12px',
        color: '#ffffff',
        minHeight: '100px',
        transition: 'all 0.2s',
    },
    label: {
        display: 'block',
        fontSize: '0.875rem',
        fontWeight: '500',
        color: '#ffffff',
        marginBottom: '8px',
    },
    mainGrid: {
        display: 'grid',
        gridTemplateColumns: 'repeat(1, minmax(0, 1fr))',
        gap: '32px',
    },
    formColumn: {
        backgroundColor: 'rgba(30, 41, 59, 0.5)',
        padding: '24px',
        borderRadius: '8px',
        border: '1px solid #334155',
        display: 'flex',
        flexDirection: 'column',
        gap: '24px',
    },
    h2: {
        fontSize: '1.5rem', // text-2xl
        fontWeight: '600',
        color: '#ffffff',
        marginBottom: '16px',
    },
    button: {
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        gap: '8px',
        width: '100%',
        backgroundColor: '#4f46e5', // indigo-600
        color: '#ffffff',
        fontWeight: '600',
        padding: '12px 16px',
        borderRadius: '6px',
        border: 'none',
        cursor: 'pointer',
        transition: 'background-color 0.3s',
    },
    buttonDisabled: {
        backgroundColor: '#475569', // slate-600
        cursor: 'not-allowed',
    },
    previewBox: {
        backgroundColor: 'rgba(15, 23, 42, 0.7)', // slate-900/70
        height: '24rem', // h-96
        borderRadius: '6px',
        padding: '16px',
        overflowY: 'auto',
        border: '1px solid #334155',
    },
    wpButton: {
        marginTop: '24px',
        backgroundColor: '#0ea5e9', // sky-600
    },
    modalOverlay: {
        position: 'fixed',
        inset: 0,
        backgroundColor: 'rgba(0, 0, 0, 0.6)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '16px',
        zIndex: 50,
    },
    modalContent: {
        backgroundColor: '#1e293b', // slate-800
        width: '100%',
        maxWidth: '448px',
        borderRadius: '8px',
        boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
        padding: '24px',
        border: '1px solid #334155',
    },
};

// Adjust styles for different screen sizes
if (window.innerWidth >= 1024) {
    styles.mainGrid.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
}


// --- Helper Components & SVGs ---
const BotIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 8V4H8"/>
        <rect width="16" height="12" x="4" y="8" rx="2"/>
        <path d="M2 14h2"/>
        <path d="M20 14h2"/>
        <path d="M15 13v2"/>
        <path d="M9 13v2"/>
    </svg>);
const SendIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="m22 2-7 20-4-9-9-4Z"/>
        <path d="m22 2-11 11"/>
    </svg>);
const WordPressIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
        <path
            d="M12 0c-6.63 0-12 5.37-12 12s5.37 12 12 12 12-5.37 12-12-5.37-12-12-12zm-0.08 2.12c1.44 0 2.8.27 4.07.78l-1.9 5.2c-.37-.11-.76-.17-1.18-.17-1.42 0-2.6.59-3.46 1.54l-2.07-2.31c1.23-1.68 3.22-2.92 5.54-3.04zm-3.5 4.39l1.73 4.77c-.4.45-.69.99-.8 1.58l-3.32.02c.33-1.42.99-2.69 1.93-3.79l.46-1.58zm8.68 1.43l-1.9 5.2c.32.06.63.09.93.09 1.3 0 2.49-.4 3.46-1.09l1.45-3.99c-1.21-.8-2.64-1.21-4.04-1.21zm-5.1 3.52c.4-.23.8-.36 1.25-.36.42 0 .82.12 1.18.33l1.83-5.02-1.1-.33-2.28 6.27zm-2.82 2.08l1.81 4.98c-1.11.45-2.31.7-3.56.7-1.25 0-2.45-.22-3.56-.63l2.25-6.17c.99.86 2.05 1.45 3.06 1.12zm7.13.23l-2.18 6.01c1.07-.48 1.96-1.22 2.65-2.18l2.1-5.75c-.87.95-1.96 1.63-3.06 1.92z"/>
    </svg>);
const Spinner = () => (<svg
    style={{animation: 'spin 1s linear infinite', marginRight: '12px', height: '20px', width: '20px', color: 'white'}}
    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
    <circle style={{opacity: 0.25}} cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
    <path style={{opacity: 0.75}} fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>);

// apikey: AIzaSyDeuUWYBVJ8AVfZ9cGELORQKYI3ATSF7UU
// pass: boSD dZg8 kTMY 1gjt BRhf rEP5

function App() {
    const [apiKey, setApiKey] = useState(process.env.REACT_APP_GEMINI_API_KEY);
    const [prompt, setPrompt] = useState('The future of renewable energy');
    const [context, setContext] = useState('Write a short, optimistic blog post about recent breakthroughs in solar and wind power technology. Mention how these advancements can help combat climate change. The tone should be accessible to a general audience.');
    const [generatedContent, setGeneratedContent] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [isWpModalOpen, setIsWpModalOpen] = useState(false);
    const [wordpressUrl, setWordpressUrl] = useState(process.env.REACT_APP_WORDPRESS_URL);
    const [wordpressUser, setWordpressUser] = useState('');
    const [wordpressAppPass, setWordpressAppPass] = useState(process.env.REACT_APP_WORDPRESS_PASSWORD);
    const [isPushing, setIsPushing] = useState(false);
    const [pushMessage, setPushMessage] = useState(null);

    const handleGenerate = async () => {

        if (!prompt) {
            setError('Please enter a blog topic/prompt.');
            return;
        }

        setIsLoading(true);
        setError(null);
        setGeneratedContent('');

        try {
            const geminiApiURL = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${apiKey}`;
            const phpBackendURL = 'http://localhost:8080/api.php';
            const fullPrompt = `${context}\n\nWrite a blog post about: ${prompt}`;
            const payload = {contents: [{parts: [{text: fullPrompt}]}]};
            const response = await fetch(phpBackendURL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error?.message || `HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.response && data.response) {
                setGeneratedContent(data.response);
            } else {
                setGeneratedContent("No content generated. The API response was empty or in an unexpected format.");
            }
        } catch (err) {
            setError(err.message);
            console.error(err);
        } finally {
            setIsLoading(false);
        }
    };

    const handlePushToWordPress = async (e) => {
        e.preventDefault();
        setIsPushing(true);
        setPushMessage(null);

        await new Promise(resolve => setTimeout(resolve, 2000)); 

        let postSuccess = false;

        if (wordpressUrl && wordpressUser && wordpressAppPass && prompt && generatedContent) {
            const postUrl = `${wordpressUrl}/wp-json/wp/v2/posts`;

            const postData = {
                title: prompt,
                content: generatedContent,
                status: 'publish' 
            };

            const authToken = btoa(`${wordpressUser}:${wordpressAppPass}`); // Basic Auth

            try {
                const response = await fetch(postUrl, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Basic ${authToken}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(postData)
                });

                if (response.ok) {
                    const data = await response.json();
                    console.log('✅ Post created:', data);
                    setPushMessage({ type: 'success', text: 'Successfully pushed to WordPress!' });
                    postSuccess = true;
                } else {
                    const error = await response.json();
                    console.error('❌ WordPress API error:', error);
                    setPushMessage({ type: 'error', text: error.message || 'Failed to push to WordPress.' });
                }
            } catch (err) {
                console.error('❌ Network error:', err);
                setPushMessage({ type: 'error', text: 'Network error. Please check the console.' });
            }
        } else {
            setPushMessage({ type: 'error', text: 'Please fill in all WordPress details.' });
        }

        setIsPushing(false);
        
        // ✅ Only close modal and reset form if post was successful
        if (postSuccess) {
            setTimeout(() => {
                setIsWpModalOpen(false);
                setPushMessage(null);

                setPrompt('The future of renewable energy');
                setContext('Write a short, optimistic blog post about recent breakthroughs in solar and wind power technology. Mention how these advancements can help combat climate change. The tone should be accessible to a general audience.');
                setGeneratedContent('');
            }, 1500);
        }
    };

    return (
        <div style={styles.container}>
            <div style={{maxWidth: '1280px', margin: '0 auto'}}>
                <header style={styles.header}>
                    <h1 style={styles.h1}><BotIcon/> AI Blog Writer </h1>
                    <p style={styles.p}> Generate blog content with Google's Gemini API and push it to WordPress. </p>
                </header>



                <main style={styles.mainGrid}>
                    <div style={styles.formColumn}>
                        <h2 style={styles.h2}>1. Create Your Content</h2>
                        <div>
                            <label htmlFor="context" style={styles.label}> Context / Instructions </label>
                            <textarea id="context" value={context} onChange={(e) => setContext(e.target.value)}
                                      style={styles.textarea}/>
                        </div>
                        <div>
                            <label htmlFor="prompt" style={styles.label}> Blog Topic / Prompt </label>
                            <input id="prompt" type="text" value={prompt} onChange={(e) => setPrompt(e.target.value)}
                                   style={styles.input}/>
                        </div>
                        <button onClick={handleGenerate} disabled={isLoading}
                                style={{...styles.button, ...((isLoading) && styles.buttonDisabled)}}>
                            {isLoading ? <><Spinner/> Generating...</> : <><SendIcon/> Generate</>}
                        </button>
                        {error && <p style={{
                            color: '#f87171',
                            backgroundColor: 'rgba(127, 29, 29, 0.5)',
                            padding: '12px',
                            borderRadius: '6px',
                            fontSize: '0.875rem'
                        }}>{error}</p>}
                    </div>

                    <div style={{...styles.formColumn, gap: 0}}>
                        <h2 style={styles.h2}>2. Preview & Publish</h2>
                        <div style={styles.previewBox}>
                            {isLoading && <p>Generating your content...</p>}
                            {!isLoading && !generatedContent &&
                                <p style={{color: '#64748b'}}>Your generated blog post will appear here.</p>}
                            {generatedContent && <pre
                                style={{whiteSpace: 'pre-wrap', fontFamily: 'sans-serif'}}>{generatedContent}</pre>}
                        </div>

                        <button onClick={() => setIsWpModalOpen(true)} disabled={!generatedContent || isLoading}
                                style={{...styles.button, ...styles.wpButton, ...((!generatedContent || isLoading) && styles.buttonDisabled)}}>
                            <WordPressIcon/> Push to WordPress
                        </button>
                    </div>
                </main>
            </div>

            {isWpModalOpen && (
                <div style={styles.modalOverlay}>
                    <div style={styles.modalContent}>
                        <h3 style={{...styles.h2, marginBottom: '16px'}}>Push to WordPress</h3>
                        <p style={{...styles.p, marginBottom: '24px', fontSize: '0.875rem'}}>Enter your WordPress site
                            details to publish. You'll need an <a
                                href="https://wordpress.org/documentation/article/application-passwords/"
                                target="_blank" rel="noopener noreferrer" style={{color: '#818cf8'}}>Application
                                Password</a>.</p>

                        {pushMessage && <div style={{
                            padding: '12px',
                            marginBottom: '16px',
                            borderRadius: '6px',
                            fontSize: '0.875rem',
                            color: pushMessage.type === 'success' ? '#6ee7b7' : '#f87171',
                            backgroundColor: pushMessage.type === 'success' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)'
                        }}>{pushMessage.text}</div>}

                        <form onSubmit={handlePushToWordPress}
                              style={{display: 'flex', flexDirection: 'column', gap: '16px'}}>
                            <div><label style={{...styles.label, marginBottom: '4px'}}>WordPress Site URL</label> <input
                                type="url" value={wordpressUrl} onChange={(e) => setWordpressUrl(e.target.value)}
                                placeholder="https://yourblog.com" style={styles.input}/></div>
                            <div><label style={{...styles.label, marginBottom: '4px'}}>Username</label> <input
                                type="text" value={wordpressUser} onChange={(e) => setWordpressUser(e.target.value)}
                                placeholder="your_wp_username" style={styles.input}/></div>
                            <div><label style={{...styles.label, marginBottom: '4px'}}>Application Password</label>
                                <input type="password" value={wordpressAppPass}
                                       onChange={(e) => setWordpressAppPass(e.target.value)}
                                       placeholder="•••• •••• •••• ••••" style={styles.input}/></div>
                            <div style={{
                                display: 'flex',
                                justifyContent: 'flex-end',
                                alignItems: 'center',
                                gap: '16px',
                                paddingTop: '16px'
                            }}>
                                <button type="button" onClick={() => setIsWpModalOpen(false)} style={{
                                    background: 'none',
                                    border: 'none',
                                    color: '#94a3b8',
                                    cursor: 'pointer'
                                }}>Cancel
                                </button>
                                <button type="submit" disabled={isPushing} style={{
                                    ...styles.button, ...styles.wpButton,
                                    width: 'auto', ...(isPushing && styles.buttonDisabled)
                                }}> {isPushing ? <><Spinner/> Pushing...</> : 'Publish Post'} </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            <style>
                {`
                @keyframes spin {
                  from { transform: rotate(0deg); }
                  to { transform: rotate(360deg); }
                }
                input:focus, textarea:focus {
                    outline: none;
                    box-shadow: 0 0 0 2px #6366f1; /* focus:ring-2 focus:ring-indigo-500 */
                }
              `}
            </style>
        </div>
    );
}

export default App;
