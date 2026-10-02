"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import styles from "./security/security.module.css";

export default function AccountSecurityNav() {
  const pathname = usePathname();
  if (pathname?.startsWith("/admin/login")) return null;
  return <nav className={styles.utility} aria-label="Staff account tools">
    <Link href="/admin">Operations home</Link>
    <Link href="/admin/security" aria-current={pathname === "/admin/security" ? "page" : undefined}>Account security</Link>
  </nav>;
}
