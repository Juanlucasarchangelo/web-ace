import styles from './FullScream.module.css'

type FullScreamProps = {
    children: React.ReactNode;
}

export function FullScream({ children }: FullScreamProps) {
    return <div className={styles.fullscream}>
        {children}
    </div>

}