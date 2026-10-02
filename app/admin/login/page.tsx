"use client";
import {FormEvent,useEffect,useState} from "react";
import {useRouter} from "next/navigation";

export default function AdminLoginPage(){
 const router=useRouter(); const [email,setEmail]=useState(""); const [password,setPassword]=useState(""); const [error,setError]=useState(""); const [loading,setLoading]=useState(false);
 useEffect(()=>{fetch("/api/admin/auth/me").then(r=>{if(r.ok)router.replace("/admin")}).catch(()=>{})},[router]);
 async function submit(e:FormEvent){e.preventDefault();setLoading(true);setError("");
  try{const r=await fetch("/api/admin/auth/login",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({email,password})});const d=await r.json();if(!r.ok)throw new Error(d.error||"Unable to sign in");router.replace("/admin");router.refresh()}
  catch(err){setError(err instanceof Error?err.message:"Unable to sign in")}finally{setLoading(false)}
 }
 return <main className="staffLoginPage"><form className="staffLoginCard" onSubmit={submit}><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Staff Portal</small></span></a><span className="eyebrow">SECURE STAFF ACCESS</span><h1>Sign in to Operations</h1><p>Use your assigned staff account. Sessions expire automatically after 12 hours.</p><label><span>Email</span><input type="email" required autoComplete="username" value={email} onChange={e=>setEmail(e.target.value)}/></label><label><span>Password</span><input type="password" required autoComplete="current-password" value={password} onChange={e=>setPassword(e.target.value)}/></label>{error&&<div className="adminError">{error}</div>}<button className="darkButton" disabled={loading}>{loading?"Signing in...":"Sign in"}</button><small className="staffLoginNote">Access is limited to authorized Halong Cruise Advisor staff.</small></form></main>
}
