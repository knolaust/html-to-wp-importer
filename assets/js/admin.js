/* global H2WPI */
window.H2WPI_Progress = (function(){
	let job = null, running = false;

	function qs(s){ return document.querySelector(s); }
	function log(line){ const el = qs('#h2wpi-log'); el.textContent += line + "\n"; el.scrollTop = el.scrollHeight; }
	function setBar(done,total){
		const pct = total ? Math.round((done/total)*100) : 0;
		const bar = qs('.h2wpi-bar span');
		bar.style.width = pct+'%';
		bar.textContent = pct+'%';
	}
	function setCounts(state){
		qs('.h2wpi-meta .done').textContent = state.done;
		qs('.h2wpi-meta .total').textContent = state.total;
		qs('.h2wpi-meta .created').textContent = state.created;
		qs('.h2wpi-meta .skipped').textContent = state.skipped;
	}

	function start(jobId){
		job = jobId;
		running = true;
		fetch(H2WPI.ajax, {
			method:'POST',
			headers:{'Content-Type':'application/x-www-form-urlencoded'},
			body: new URLSearchParams({action:'h2wpi_start_job', nonce:H2WPI.nonce, job})
		}).then(r=>r.json()).then(data=>{
			if(!data.success){ log('Error starting job'); return; }
			tick();
		});
	}

	function tick(){
		if(!running) return;
		fetch(H2WPI.ajax, {
			method:'POST',
			headers:{'Content-Type':'application/x-www-form-urlencoded'},
			body: new URLSearchParams({action:'h2wpi_run_batch', nonce:H2WPI.nonce, job, batch: 15})
		}).then(r=>r.json()).then(data=>{
			if(!data.success){ log('Error running batch'); return; }
			const st = data.data.state;
			setCounts(st); setBar(st.done, st.total);
			if (st.log && st.log.length){
				const last = st.log.splice(-15); // show last chunk only
				last.forEach(l=>log(l));
			}
			if (data.data.done){
				log('All done.');
				running = false;
			} else {
				setTimeout(tick, 400);
			}
		}).catch(()=>log('Network error.'));
	}

	// hijack form submit: turn on progress panel
	document.addEventListener('submit', function(e){
		const f = e.target;
		if (f && f.id === 'h2wpi-form'){
			const prog = document.getElementById('h2wpi-progress');
			prog.classList.remove('hidden');
			qs('#h2wpi-log').textContent='';
		}
	}, true);

	return { start };
})();